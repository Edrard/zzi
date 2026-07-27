<?php

namespace Tests\Feature\Services\Znuny;

use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Models\AuditLog;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\User;
use App\Models\ZnunyTicketCreationAttempt;
use App\Services\AuditLogger;
use App\Services\Znuny\ScheduledZnunyTaskRunRetryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduledZnunyTaskRunRetryServiceTest extends TestCase
{
    use RefreshDatabase;

    private ScheduledZnunyTaskRunRetryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScheduledZnunyTaskRunRetryService(new AuditLogger);
    }

    public function test_first_retry_creates_one_child_and_resolves_root()
    {
        $task = ScheduledZnunyTask::create(['name' => 'T', 'enabled' => true, 'queue_name' => 'Q1', 'subject' => 'S', 'body' => 'B']);
        $rootRun = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => 'T',
            'run_type' => 'scheduled',
            'status' => 'failed',
            'scheduled_for' => now(),
            'root_run_id' => null,
            'parent_run_id' => null,
            'retry_sequence' => 0,
        ]);
        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $rootRun->id,
            'marker' => 'M1',
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'subject_original' => 'Subject',
            'body_original' => 'Body',
            'subject_sent' => 'Subject',
            'body_sent' => 'Body',
        ]);

        $actor = User::factory()->create();
        $result = $this->service->retry($rootRun->id, $actor);

        $this->assertTrue($result['created']);
        $this->assertEquals($rootRun->id, $result['root_run_id']);
        $this->assertEquals($rootRun->id, $result['leaf_run_id']);
        $this->assertNotNull($result['retry_run_id']);

        $retryRun = ScheduledZnunyTaskRun::find($result['retry_run_id']);
        $this->assertEquals($rootRun->id, $retryRun->root_run_id);
        $this->assertEquals($rootRun->id, $retryRun->parent_run_id);
        $this->assertEquals(1, $retryRun->retry_sequence);
        $this->assertEquals($attempt->id, $retryRun->manual_retry_of_attempt_id);
        $this->assertEquals($actor->id, $retryRun->created_by);

        $audit = AuditLog::where('action', 'scheduled_znuny_run_retry_created')
            ->where('entity_id', (string) $rootRun->id)
            ->first();
        $this->assertNotNull($audit);
        $this->assertEquals($actor->id, $audit->user_id);
        $this->assertEquals($rootRun->id, $audit->context['selected_run_id']);
        $this->assertEquals($rootRun->id, $audit->context['root_run_id']);
        $this->assertEquals($rootRun->id, $audit->context['replaced_leaf_run_id']);
        $this->assertEquals($retryRun->id, $audit->context['retry_run_id']);

        $rootRun->refresh();
        $this->assertNotNull($rootRun->resolved_at);
        $this->assertEquals(ScheduledZnunyTaskRun::RESOLUTION_TYPE_RETRY_CREATED, $rootRun->resolution_type);
    }

    public function test_retry_from_stale_root_after_child_failure()
    {
        $task = ScheduledZnunyTask::create(['name' => 'T', 'enabled' => true, 'queue_name' => 'Q1', 'subject' => 'S', 'body' => 'B']);
        $run12 = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => 'T',
            'run_type' => 'scheduled',
            'status' => 'failed',
            'scheduled_for' => now(),
            'resolved_at' => now(),
            'resolution_type' => ScheduledZnunyTaskRun::RESOLUTION_TYPE_RETRY_CREATED,
        ]);
        $attempt12 = ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $run12->id,
            'marker' => 'M1',
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'subject_original' => 'Subject',
            'body_original' => 'Body',
            'subject_sent' => 'Subject',
            'body_sent' => 'Body',
        ]);

        $run13 = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => 'T',
            'run_type' => 'manual_retry',
            'status' => 'failed',
            'scheduled_for' => now()->addSecond(),
            'root_run_id' => $run12->id,
            'parent_run_id' => $run12->id,
            'retry_sequence' => 1,
            'manual_retry_of_attempt_id' => $attempt12->id,
        ]);

        $authActor = User::factory()->create();
        $this->actingAs($authActor);

        $result = $this->service->retry($run12->id);

        $this->assertTrue($result['created']);
        $this->assertEquals($run12->id, $result['root_run_id']);
        $this->assertEquals($run13->id, $result['leaf_run_id']);

        $run14 = ScheduledZnunyTaskRun::find($result['retry_run_id']);
        $this->assertEquals($run12->id, $run14->root_run_id);
        $this->assertEquals($run13->id, $run14->parent_run_id);
        $this->assertEquals(2, $run14->retry_sequence);
        $this->assertNull($run14->manual_retry_of_attempt_id);
        $this->assertNull($run14->created_by);

        $audit = AuditLog::where('action', 'scheduled_znuny_run_retry_created')
            ->where('entity_id', (string) $run12->id)
            ->first();
        $this->assertNotNull($audit);
        $this->assertNull($audit->user_id);

        $run13->refresh();
        $this->assertNotNull($run13->resolved_at);
        $this->assertEquals(ScheduledZnunyTaskRun::RESOLUTION_TYPE_RETRY_CREATED, $run13->resolution_type);

        $this->assertEquals(1, ScheduledZnunyTaskRun::where('parent_run_id', $run12->id)->count());
    }

    public function test_active_leaf_returns_existing()
    {
        $task = ScheduledZnunyTask::create(['name' => 'T', 'enabled' => true, 'queue_name' => 'Q1', 'subject' => 'S', 'body' => 'B']);

        foreach (['pending', 'running'] as $index => $status) {
            $rootRun = ScheduledZnunyTaskRun::create([
                'scheduled_znuny_task_id' => $task->id,
                'task_name_snapshot' => 'T',
                'run_type' => 'scheduled',
                'status' => $status,
                'scheduled_for' => now()->addSeconds($index + 1),
            ]);

            $result = $this->service->retry($rootRun->id);

            $this->assertFalse($result['created']);
            $this->assertTrue($result['existing']);
            $this->assertEquals($rootRun->id, $result['retry_run_id']);

            $rootRun->refresh();
            $this->assertNull($rootRun->resolved_at);
        }
    }

    public function test_closed_leaf_returns_closed()
    {
        $task = ScheduledZnunyTask::create(['name' => 'T', 'enabled' => true, 'queue_name' => 'Q1', 'subject' => 'S', 'body' => 'B']);

        $rootRun1 = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => 'T',
            'run_type' => 'scheduled',
            'status' => 'failed',
            'scheduled_for' => now(),
            'resolved_at' => now(),
            'resolution_type' => 'manual_link',
        ]);
        $result1 = $this->service->retry($rootRun1->id);
        $this->assertTrue($result1['closed']);

        $rootRun2 = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => 'T',
            'run_type' => 'scheduled',
            'status' => 'success',
            'scheduled_for' => now()->addSecond(),
        ]);
        $result2 = $this->service->retry($rootRun2->id);
        $this->assertTrue($result2['closed']);
    }

    public function test_idempotency_database_uniqueness_and_malformed_traversal()
    {
        $task = ScheduledZnunyTask::create(['name' => 'T', 'enabled' => true, 'queue_name' => 'Q1', 'subject' => 'S', 'body' => 'B']);
        $rootRun = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => 'T',
            'run_type' => 'scheduled',
            'status' => 'failed',
            'scheduled_for' => now(),
        ]);

        $result1 = $this->service->retry($rootRun->id);
        $this->assertTrue($result1['created']);
        $this->assertNotNull($result1['retry_run_id']);

        $result2 = $this->service->retry($rootRun->id);
        $this->assertFalse($result2['created']);
        $this->assertTrue($result2['existing']);
        $this->assertEquals($result1['retry_run_id'], $result2['retry_run_id']);

        $this->assertEquals(1, ScheduledZnunyTaskRun::where('parent_run_id', $rootRun->id)->count());

        try {
            ScheduledZnunyTaskRun::create([
                'scheduled_znuny_task_id' => $task->id,
                'task_name_snapshot' => 'T',
                'run_type' => 'manual_retry',
                'status' => 'pending',
                'scheduled_for' => now()->addSeconds(10),
                'parent_run_id' => $rootRun->id,
            ]);
            $this->fail('Expected unique constraint failure.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('UNIQUE constraint failed', $e->getMessage());
        }

        $malformedRoot = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => 'T',
            'run_type' => 'scheduled',
            'status' => 'failed',
            'scheduled_for' => now()->addSeconds(50),
            'root_run_id' => null,
            'parent_run_id' => null,
            'retry_sequence' => 1,
        ]);

        try {
            $malformedRoot->effectiveRoot();
            $this->fail('Expected LogicException for malformed root.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('claims to be a root but has a parent or non-zero retry sequence', $e->getMessage());
        }

        $malformedResult = $this->service->retry($malformedRoot->id);
        $this->assertFalse($malformedResult['created']);
        $this->assertFalse($malformedResult['existing']);
        $this->assertFalse($malformedResult['closed']);
        $this->assertNull($malformedResult['retry_run_id']);
        $this->assertNotNull($malformedResult['reason']);
        $this->assertStringContainsString('not a valid root', $malformedResult['reason']);

        $this->assertEquals(0, ScheduledZnunyTaskRun::where('parent_run_id', $malformedRoot->id)->count());

        $cyclicRun1 = ScheduledZnunyTaskRun::create(['scheduled_znuny_task_id' => $task->id, 'task_name_snapshot' => 'T', 'run_type' => 'scheduled', 'status' => 'failed', 'scheduled_for' => now()->addSeconds(20)]);
        $cyclicRun2 = ScheduledZnunyTaskRun::create(['scheduled_znuny_task_id' => $task->id, 'task_name_snapshot' => 'T', 'run_type' => 'scheduled', 'status' => 'failed', 'scheduled_for' => now()->addSeconds(21), 'parent_run_id' => $cyclicRun1->id]);

        DB::table('scheduled_znuny_task_runs')->where('id', $cyclicRun1->id)->update(['parent_run_id' => $cyclicRun2->id]);

        try {
            $cyclicRun1->refresh()->currentLeaf();
            $this->fail('Expected LogicException for cycle.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Cycle detected', $e->getMessage());
        }
    }
}
