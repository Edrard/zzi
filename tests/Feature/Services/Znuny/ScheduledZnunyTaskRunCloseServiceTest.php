<?php

namespace Tests\Feature\Services\Znuny;

use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Models\AuditLog;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\User;
use App\Models\ZnunyTicketCreationAttempt;
use App\Services\AuditLogger;
use App\Services\Znuny\ScheduledZnunyTaskRunCloseService;
use Exception;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ScheduledZnunyTaskRunCloseServiceTest extends TestCase
{
    use RefreshDatabase;

    private ScheduledZnunyTaskRunCloseService $service;

    private int $runCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScheduledZnunyTaskRunCloseService(new AuditLogger);
    }

    private function createTask(): ScheduledZnunyTask
    {
        return ScheduledZnunyTask::create([
            'name' => 'Test Task '.bin2hex(random_bytes(5)),
            'enabled' => true,
            'queue_name' => 'Q1',
            'subject' => 'Subject',
            'body' => 'Body',
        ]);
    }

    private function createRun(ScheduledZnunyTask $task, array $overrides = []): ScheduledZnunyTaskRun
    {
        $this->runCounter++;

        return ScheduledZnunyTaskRun::create(array_merge([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => 'Test Task',
            'run_type' => 'scheduled',
            'status' => 'failed',
            'scheduled_for' => now()->addSeconds($this->runCounter),
            'root_run_id' => null,
            'parent_run_id' => null,
            'retry_sequence' => 0,
        ], $overrides));
    }

    private function createAttempt(ScheduledZnunyTaskRun $run, string $status): ZnunyTicketCreationAttempt
    {
        return ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $run->id,
            'marker' => 'M_'.$run->id,
            'status' => $status,
            'subject_original' => 'Subject',
            'body_original' => 'Body',
            'subject_sent' => 'Subject',
            'body_sent' => 'Body',
        ]);
    }

    // 1. Successful uncertain root close
    public function test_uncertain_root_with_uncertain_attempt_closes_successfully(): void
    {
        $task = $this->createTask();
        $run = $this->createRun($task, ['status' => 'uncertain', 'ticket_id' => 'T123', 'ticket_number' => '123456']);
        $attempt = $this->createAttempt($run, ZnunyTicketCreationAttemptStatus::Uncertain->value);

        Carbon::setTestNow('2026-07-30 12:00:00');

        $actor = User::factory()->create();
        $result = $this->service->close($run->id, $actor);

        $this->assertTrue($result['closed']);
        $this->assertTrue($result['transitioned']);
        $this->assertFalse($result['existing']);
        $this->assertEquals('uncertain', $result['technical_status']);
        $this->assertEquals('manual_closed', $result['resolution_type']);
        $this->assertNull($result['reason']);

        $run->refresh();
        $this->assertEquals('uncertain', $run->status);
        $this->assertEquals('manual_closed', $run->resolution_type);
        $this->assertEquals('2026-07-30 12:00:00', $run->resolved_at->format('Y-m-d H:i:s'));

        $attempt->refresh();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::ResolvedWithoutTicket, $attempt->status);
        $this->assertEquals('2026-07-30 12:00:00', $attempt->finished_at->format('Y-m-d H:i:s'));

        $task->refresh();
        $this->assertEquals('success', $task->last_status);
        $this->assertNull($task->last_error_summary);
        $this->assertEquals('T123', $task->last_ticket_id);
        $this->assertEquals('123456', $task->last_ticket_number);

        $audit = AuditLog::where('action', 'scheduled_znuny_run_manually_closed')->first();
        $this->assertNotNull($audit);
        $this->assertEquals('ScheduledZnunyTaskRun', $audit->entity_type);
        $this->assertEquals((string) $run->id, $audit->entity_id);
        $this->assertEquals($actor->id, $audit->user_id);
        $this->assertEquals($run->id, $audit->context['leaf_run_id']);
        $this->assertEquals('manual_closed', $audit->context['resolution_type']);
        $this->assertEquals('uncertain', $audit->context['technical_status']);
        $this->assertEquals($attempt->id, $audit->context['attempt_id']);
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Uncertain->value, $audit->context['previous_attempt_status']);
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::ResolvedWithoutTicket->value, $audit->context['new_attempt_status']);
    }

    // 2. Successful failed root without attempt
    public function test_failed_root_with_no_attempt_closes_successfully(): void
    {
        $task = $this->createTask();
        $run = $this->createRun($task, ['status' => 'failed']);

        $actor = User::factory()->create();
        $result = $this->service->close($run->id, $actor);

        $this->assertTrue($result['closed']);
        $this->assertTrue($result['transitioned']);
        $this->assertEquals('failed', $result['technical_status']);

        $run->refresh();
        $this->assertEquals('failed', $run->status);
        $this->assertEquals('manual_closed', $run->resolution_type);
        $this->assertNotNull($run->resolved_at);

        $this->assertEquals(0, ZnunyTicketCreationAttempt::count());
    }

    // 3. Valid multi-run chain
    public function test_current_failed_leaf_in_valid_chain_closes_successfully(): void
    {
        $task = $this->createTask();
        $root = $this->createRun($task, ['status' => 'failed', 'resolved_at' => now(), 'resolution_type' => 'retry_created']);
        $child = $this->createRun($task, [
            'status' => 'failed',
            'root_run_id' => $root->id,
            'parent_run_id' => $root->id,
            'retry_sequence' => 1,
        ]);

        $actor = User::factory()->create();
        $result = $this->service->close($child->id, $actor);

        $this->assertTrue($result['closed']);
        $this->assertTrue($result['transitioned']);

        $child->refresh();
        $this->assertEquals('failed', $child->status);
        $this->assertEquals('manual_closed', $child->resolution_type);
        $this->assertNotNull($child->resolved_at);

        $root->refresh();
        $this->assertEquals('retry_created', $root->resolution_type);
        $this->assertEquals('failed', $root->status);
        $this->assertEquals(0, $root->retry_sequence);
        $this->assertNull($root->root_run_id);
    }

    // 4. Status rejection
    public static function invalidStatusProvider(): array
    {
        return [
            ['pending'],
            ['running'],
            ['success'],
            ['skipped'],
            ['duplicate'],
        ];
    }

    #[DataProvider('invalidStatusProvider')]
    public function test_invalid_status_rejection(string $status): void
    {
        $task = $this->createTask();
        $run = $this->createRun($task, ['status' => $status]);

        $actor = User::factory()->create();
        $result = $this->service->close($run->id, $actor);

        $this->assertFalse($result['closed']);
        $this->assertFalse($result['transitioned']);
        $this->assertNotNull($result['reason']);
        $this->assertEquals('The selected run is not eligible for manual close.', $result['reason']);

        $run->refresh();
        $this->assertNull($run->resolved_at);
        $this->assertEquals(0, AuditLog::where('action', 'scheduled_znuny_run_manually_closed')->count());
    }

    // 5. Successful-attempt rejection
    public static function successfulAttemptStatusProvider(): array
    {
        return [
            [ZnunyTicketCreationAttemptStatus::Success->value],
            [ZnunyTicketCreationAttemptStatus::Recovered->value],
            [ZnunyTicketCreationAttemptStatus::ManuallyLinked->value],
        ];
    }

    #[DataProvider('successfulAttemptStatusProvider')]
    public function test_attempt_in_successful_states_rejected(string $status): void
    {
        $task = $this->createTask();
        $run = $this->createRun($task, ['status' => 'failed']);
        $attempt = $this->createAttempt($run, $status);

        $actor = User::factory()->create();
        $result = $this->service->close($run->id, $actor);

        $this->assertFalse($result['closed']);
        $this->assertFalse($result['transitioned']);
        $this->assertStringContainsString('already successful', $result['reason']);

        $run->refresh();
        $this->assertNull($run->resolved_at);

        $attempt->refresh();
        $this->assertEquals($status, $attempt->status->value);
        $this->assertEquals(0, AuditLog::where('action', 'scheduled_znuny_run_manually_closed')->count());
    }

    // 6. Unsafe-attempt rejection
    public static function unsafeAttemptStatusProvider(): array
    {
        return [
            [ZnunyTicketCreationAttemptStatus::Preparing->value],
            [ZnunyTicketCreationAttemptStatus::Sending->value],
            [ZnunyTicketCreationAttemptStatus::Orphaned->value],
        ];
    }

    #[DataProvider('unsafeAttemptStatusProvider')]
    public function test_attempt_in_unsafe_states_rejected(string $status): void
    {
        $task = $this->createTask();
        $run = $this->createRun($task, ['status' => 'failed']);
        $attempt = $this->createAttempt($run, $status);

        $actor = User::factory()->create();
        $result = $this->service->close($run->id, $actor);

        $this->assertFalse($result['closed']);
        $this->assertFalse($result['transitioned']);
        $this->assertStringContainsString('unsafe or ambiguous', $result['reason']);

        $run->refresh();
        $this->assertNull($run->resolved_at);

        $attempt->refresh();
        $this->assertEquals($status, $attempt->status->value);
        $this->assertEquals(0, AuditLog::where('action', 'scheduled_znuny_run_manually_closed')->count());
    }

    // 7. Safe terminal attempt behavior
    public static function safeAttemptStatusProvider(): array
    {
        return [
            [ZnunyTicketCreationAttemptStatus::ConfirmedFailed->value],
            [ZnunyTicketCreationAttemptStatus::ResolvedWithoutTicket->value],
        ];
    }

    #[DataProvider('safeAttemptStatusProvider')]
    public function test_attempt_in_safe_states_allows_close(string $status): void
    {
        $task = $this->createTask();
        $run = $this->createRun($task, ['status' => 'failed']);
        $attempt = $this->createAttempt($run, $status);

        $actor = User::factory()->create();
        $result = $this->service->close($run->id, $actor);

        $this->assertTrue($result['closed']);
        $this->assertTrue($result['transitioned']);

        $attempt->refresh();
        $this->assertEquals($status, $attempt->status->value);
    }

    // 8. Non-leaf rejection
    public function test_non_leaf_rejection(): void
    {
        $task = $this->createTask();
        $root = $this->createRun($task, ['status' => 'failed', 'resolved_at' => now(), 'resolution_type' => 'retry_created']);
        $child = $this->createRun($task, [
            'status' => 'failed',
            'root_run_id' => $root->id,
            'parent_run_id' => $root->id,
            'retry_sequence' => 1,
        ]);

        $actor = User::factory()->create();
        $result = $this->service->close($root->id, $actor);

        $this->assertFalse($result['closed']);
        $this->assertFalse($result['transitioned']);
        $this->assertStringContainsString('not the current leaf', $result['reason']);
        $this->assertEquals(0, AuditLog::where('action', 'scheduled_znuny_run_manually_closed')->count());
    }

    // 9. Stale root or old-leaf rejection after a child exists
    public function test_stale_close_after_retry_child_created(): void
    {
        $task = $this->createTask();
        $root = $this->createRun($task, ['status' => 'failed']);

        $actor = User::factory()->create();

        // Simulate race: another process creates retry
        $child = $this->createRun($task, [
            'status' => 'failed',
            'root_run_id' => $root->id,
            'parent_run_id' => $root->id,
            'retry_sequence' => 1,
        ]);
        $root->update(['resolved_at' => now(), 'resolution_type' => 'retry_created']);

        $result = $this->service->close($root->id, $actor);

        $this->assertFalse($result['closed']);
        $this->assertFalse($result['transitioned']);
        $this->assertNotNull($result['reason']);
        $this->assertStringContainsString('not the current leaf', $result['reason']);
    }

    // 10. Idempotent second close
    public function test_idempotent_second_close(): void
    {
        $task = $this->createTask();
        $run = $this->createRun($task, ['status' => 'failed']);

        $actor = User::factory()->create();
        $result1 = $this->service->close($run->id, $actor);
        $this->assertTrue($result1['closed']);
        $this->assertTrue($result1['transitioned']);
        $this->assertFalse($result1['existing']);

        $this->assertEquals(1, AuditLog::where('action', 'scheduled_znuny_run_manually_closed')->count());

        $resolvedAt = ScheduledZnunyTaskRun::find($run->id)->resolved_at;

        Carbon::setTestNow(now()->addSeconds(10));

        $result2 = $this->service->close($run->id, $actor);
        $this->assertTrue($result2['closed']);
        $this->assertFalse($result2['transitioned']);
        $this->assertTrue($result2['existing']);

        $run->refresh();
        $this->assertEquals('manual_closed', $run->resolution_type);
        $this->assertEquals($resolvedAt->format('Y-m-d H:i:s'), $run->resolved_at->format('Y-m-d H:i:s'));

        $this->assertEquals(1, AuditLog::where('action', 'scheduled_znuny_run_manually_closed')->count());
    }

    // 11. Malformed lineage tests
    public function test_malformed_fork_with_two_children(): void
    {
        DB::statement('PRAGMA foreign_keys=OFF;');
        Schema::table('scheduled_znuny_task_runs', function (Blueprint $table) {
            $table->dropUnique(['parent_run_id']);
        });

        $task = $this->createTask();
        $root = $this->createRun($task, ['status' => 'failed']);
        $child1 = $this->createRun($task, ['root_run_id' => $root->id, 'parent_run_id' => $root->id, 'retry_sequence' => 1]);
        $child2 = $this->createRun($task, ['root_run_id' => $root->id, 'parent_run_id' => $root->id, 'retry_sequence' => 1]);

        $actor = User::factory()->create();
        $result = $this->service->close($child1->id, $actor);

        $this->assertFalse($result['closed']);
        $this->assertStringContainsString('Fork detected', $result['reason']);
    }

    public function test_malformed_retry_sequence_gap(): void
    {
        $task = $this->createTask();
        $root = $this->createRun($task, ['status' => 'failed']);
        $child = $this->createRun($task, ['root_run_id' => $root->id, 'parent_run_id' => $root->id, 'retry_sequence' => 2]);

        $actor = User::factory()->create();
        $result = $this->service->close($child->id, $actor);

        $this->assertFalse($result['closed']);
        $this->assertStringContainsString('retry_sequence mismatch', $result['reason']);
    }

    public function test_malformed_wrong_task_id(): void
    {
        $task = $this->createTask();
        $otherTask = $this->createTask();
        $root = $this->createRun($task, ['status' => 'failed']);
        $child = $this->createRun($otherTask, ['root_run_id' => $root->id, 'parent_run_id' => $root->id, 'retry_sequence' => 1]);

        $actor = User::factory()->create();
        $result = $this->service->close($child->id, $actor);

        $this->assertFalse($result['closed']);
        $this->assertStringContainsString('different scheduled_znuny_task_id', $result['reason']);
    }

    public function test_malformed_wrong_root_run_id(): void
    {
        $task = $this->createTask();
        $root = $this->createRun($task, ['status' => 'failed']);
        $otherRoot = $this->createRun($task, ['status' => 'failed']);

        $child = $this->createRun($task, ['root_run_id' => $otherRoot->id, 'parent_run_id' => $root->id, 'retry_sequence' => 1]);

        $actor = User::factory()->create();
        $result = $this->service->close($root->id, $actor); // Closes root, but child points to root and declares otherRoot

        $this->assertFalse($result['closed']);
        $this->assertStringContainsString('A child outside the declared member set points to a chain member', $result['reason']);
    }

    public function test_malformed_parent_outside_chain(): void
    {
        $task = $this->createTask();
        $root = $this->createRun($task, ['status' => 'failed']);
        $otherRoot = $this->createRun($task, ['status' => 'failed']);

        DB::statement('PRAGMA foreign_keys=OFF;');
        $child = $this->createRun($task, ['root_run_id' => $root->id, 'parent_run_id' => $otherRoot->id, 'retry_sequence' => 1]);

        $actor = User::factory()->create();
        $result = $this->service->close($child->id, $actor);

        $this->assertFalse($result['closed']);
        $this->assertStringContainsString('parent outside the locked member set', $result['reason']);
    }

    public function test_malformed_missing_root(): void
    {
        DB::rollBack();
        DB::statement('PRAGMA foreign_keys=OFF;');

        $task = $this->createTask();
        $root = $this->createRun($task, ['status' => 'failed']);
        $child = $this->createRun($task, ['root_run_id' => $root->id, 'parent_run_id' => $root->id, 'retry_sequence' => 1]);
        $root->delete();

        DB::beginTransaction();

        $actor = User::factory()->create();
        $result = $this->service->close($child->id, $actor);

        $this->assertFalse($result['closed']);
        $this->assertStringContainsString('invalid or no longer exists', $result['reason']);
    }

    public function test_malformed_detached_declared_member(): void
    {
        DB::statement('PRAGMA foreign_keys=OFF;');
        Schema::table('scheduled_znuny_task_runs', function (Blueprint $table) {
            $table->dropUnique(['parent_run_id']);
        });

        $task = $this->createTask();
        $root = $this->createRun($task, ['status' => 'failed']);
        $child1 = $this->createRun($task, ['root_run_id' => $root->id, 'parent_run_id' => $root->id, 'retry_sequence' => 1]);

        // This member is declared as part of the root chain, but it forms a cycle disconnected from root
        $child2 = $this->createRun($task, ['root_run_id' => $root->id, 'parent_run_id' => null, 'retry_sequence' => 2]);
        $child2->update(['parent_run_id' => $child2->id]); // Disconnected cycle

        $actor = User::factory()->create();
        $result = $this->service->close($child1->id, $actor);

        $this->assertFalse($result['closed']);
    }

    public function test_malformed_depth_beyond_max(): void
    {
        $task = $this->createTask();
        $root = $this->createRun($task, ['status' => 'failed']);
        $current = $root;

        for ($i = 1; $i <= ScheduledZnunyTaskRun::MAX_RETRY_CHAIN_DEPTH + 1; $i++) {
            $current = $this->createRun($task, ['root_run_id' => $root->id, 'parent_run_id' => $current->id, 'retry_sequence' => $i]);
        }

        $actor = User::factory()->create();
        $result = $this->service->close($current->id, $actor);

        $this->assertFalse($result['closed']);
        $this->assertStringContainsString('Maximum retry chain depth exceeded', $result['reason']);
    }

    // 12. Transaction failure
    public function test_transaction_failure(): void
    {
        $task = $this->createTask();
        $run = $this->createRun($task, ['status' => 'failed']);

        ScheduledZnunyTaskRun::saving(function () {
            throw new Exception('DB crashed');
        });

        $actor = User::factory()->create();
        $result = $this->service->close($run->id, $actor);

        $this->assertFalse($result['closed']);
        $this->assertFalse($result['transitioned']);
        $this->assertFalse($result['existing']);
        $this->assertEquals('A transaction error occurred during close.', $result['reason']);
        $this->assertEquals(0, AuditLog::where('action', 'scheduled_znuny_run_manually_closed')->count());

        // Remove the listener to not break other tests if this isn't isolated
        ScheduledZnunyTaskRun::flushEventListeners();
    }

    // 13. Audit failure
    public function test_audit_failure_leaves_close_committed(): void
    {
        $mockAudit = Mockery::mock(AuditLogger::class);
        $mockAudit->shouldReceive('log')->andThrow(new RuntimeException('Audit failed'));

        $service = new ScheduledZnunyTaskRunCloseService($mockAudit);

        $task = $this->createTask();
        $run = $this->createRun($task, ['status' => 'failed']);

        Log::shouldReceive('error')->once()->withArgs(function ($msg, $context) {
            return str_contains($msg, 'Audit log failed') && isset($context['leaf_run_id']);
        });

        $actor = User::factory()->create();
        $result = $service->close($run->id, $actor);

        $this->assertTrue($result['closed']);
        $this->assertTrue($result['transitioned']);

        $run->refresh();
        $this->assertEquals('manual_closed', $run->resolution_type);
    }

    // 14. Ambiguous fork
    public function test_ambiguous_fork(): void
    {
        DB::statement('PRAGMA foreign_keys=OFF;');
        Schema::table('scheduled_znuny_task_runs', function (Blueprint $table) {
            $table->dropUnique(['parent_run_id']);
        });

        $task = $this->createTask();
        $root = $this->createRun($task, ['status' => 'failed']);
        $child1 = $this->createRun($task, ['root_run_id' => $root->id, 'parent_run_id' => $root->id, 'retry_sequence' => 1]);
        $child2 = $this->createRun($task, ['root_run_id' => $root->id, 'parent_run_id' => $root->id, 'retry_sequence' => 1]);

        $actor = User::factory()->create();
        $result = $this->service->close($child2->id, $actor);

        $this->assertFalse($result['closed']);
        $this->assertStringContainsString('Fork detected', $result['reason']);
    }
}
