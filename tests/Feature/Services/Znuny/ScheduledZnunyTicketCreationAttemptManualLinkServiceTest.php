<?php

namespace Tests\Feature\Services\Znuny;

use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Models\AuditLog;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\ZnunyTicketCreationAttempt;
use App\Services\AuditLogger;
use App\Services\Znuny\ScheduledZnunyTicketCreationAttemptManualLinkService;
use App\Services\Znuny\ScheduledZnunyTicketCreationAttemptManualReviewService;
use App\Services\Znuny\ScheduledZnunyTicketMarkerLookupService;
use App\Services\Znuny\ScheduledZnunyTicketMarkerRefreshLookupService;
use App\Services\Znuny\ZnunyTicketWorkspaceCacheReader;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ScheduledZnunyTicketCreationAttemptManualLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    private function registerDependenciesAndGetService(
        ZnunyTicketWorkspaceCacheReader $reader,
        ?Kernel $kernel = null,
        ?AuditLogger $audit = null,
    ): ScheduledZnunyTicketCreationAttemptManualLinkService {
        $audit ??= new AuditLogger;

        $this->app->instance(ZnunyTicketWorkspaceCacheReader::class, $reader);
        $this->app->instance(Kernel::class, $kernel ?? $this->createStub(Kernel::class));
        $this->app->instance(AuditLogger::class, $audit);

        foreach ([
            ScheduledZnunyTicketMarkerLookupService::class,
            ScheduledZnunyTicketMarkerRefreshLookupService::class,
            ScheduledZnunyTicketCreationAttemptManualReviewService::class,
            ScheduledZnunyTicketCreationAttemptManualLinkService::class,
        ] as $service) {
            $this->app->forgetInstance($service);
        }

        $this->app->instance(
            ScheduledZnunyTicketCreationAttemptManualLinkService::class,
            new ScheduledZnunyTicketCreationAttemptManualLinkService(
                $this->app->make(ScheduledZnunyTicketCreationAttemptManualReviewService::class),
                $audit,
            )
        );

        return $this->app->make(ScheduledZnunyTicketCreationAttemptManualLinkService::class);
    }

    public function test_manual_link_persists_ticket_identifiers(): void
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Manual link test',
            'enabled' => true,
            'cron_expression' => '0 0 * * *',
            'timezone' => 'UTC',
            'last_status' => 'failed',
            'last_error_summary' => 'Existing task error',
        ]);

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'status' => 'uncertain',
            'scheduled_for' => now(),
            'error_summary' => 'Existing run error',
            'error_details' => 'Existing run details',
        ]);

        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $run->id,
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'marker' => 'MARKER123',
            'subject_original' => 'Original subject',
            'subject_sent' => 'Original subject [MARKER123]',
            'started_at' => now(),
        ]);

        $reader = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->expects($this->once())
            ->method('getTickets')
            ->willReturn([[
                'TicketID' => 10,
                'TicketNumber' => 'TN10',
                'Title' => 'Notification for [MARKER123] issue',
                'StateType' => 'open',
            ]]);

        $linkService = $this->registerDependenciesAndGetService($reader);

        $result = $linkService->link($attempt->id, '10', ' TN10 ');

        $this->assertTrue($result['linked']);
        $this->assertTrue($result['transitioned']);

        $attempt->refresh();
        $run->refresh();
        $task->refresh();

        $this->assertSame(
            ZnunyTicketCreationAttemptStatus::ManuallyLinked,
            $attempt->status
        );
        $this->assertSame(10, (int) $attempt->ticket_id);
        $this->assertSame('TN10', $attempt->ticket_number);
        $this->assertNotNull($attempt->finished_at);

        $this->assertSame(10, (int) $run->ticket_id);
        $this->assertSame('TN10', $run->ticket_number);
        $this->assertNotNull($run->resolved_at);
        $this->assertSame('manual_link', $run->resolution_type);
        $this->assertSame('uncertain', $run->status);
        $this->assertSame('Existing run error', $run->error_summary);
        $this->assertSame('Existing run details', $run->error_details);

        $this->assertSame('success', $task->last_status);
        $this->assertNull($task->last_error_summary);
        $this->assertSame(10, (int) $task->last_ticket_id);
        $this->assertSame('TN10', $task->last_ticket_number);

        $audit = AuditLog::where('action', 'scheduled_znuny_attempt_manually_linked')
            ->where('entity_type', 'ZnunyTicketCreationAttempt')
            ->where('entity_id', $attempt->id)
            ->sole();

        $this->assertSame('manual_link', $audit->context['resolution_type'] ?? null);
        $this->assertSame($run->id, $audit->context['run_id'] ?? null);
        $this->assertSame($task->id, $audit->context['task_id'] ?? null);
        $this->assertSame(10, $audit->context['ticket_id'] ?? null);
        $this->assertSame('TN10', $audit->context['ticket_number'] ?? null);
    }

    public function test_invalid_ticket_id_is_rejected_without_lookup_or_mutation(): void
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Invalid ticket ID test',
            'enabled' => true,
            'cron_expression' => '0 0 * * *',
            'timezone' => 'UTC',
        ]);

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'status' => 'uncertain',
            'scheduled_for' => now(),
        ]);

        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $run->id,
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'marker' => 'MARKER123',
            'subject_original' => 'Original subject',
            'subject_sent' => 'Original subject [MARKER123]',
            'started_at' => now(),
        ]);

        $reader = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->expects($this->never())->method('getTickets');

        $kernel = $this->createMock(Kernel::class);
        $kernel->expects($this->never())->method('call');

        $linkService = $this->registerDependenciesAndGetService($reader, $kernel);

        $result = $linkService->link($attempt->id, 0, 'TN0');

        $this->assertFalse($result['linked']);
        $this->assertFalse($result['transitioned']);
        $this->assertSame($attempt->id, $result['attempt_id']);
        $this->assertSame('Unavailable', $result['lookup_status']->name ?? null);

        $attempt->refresh();
        $run->refresh();
        $task->refresh();

        $this->assertSame(ZnunyTicketCreationAttemptStatus::Uncertain, $attempt->status);
        $this->assertNull($attempt->ticket_id);
        $this->assertNull($attempt->ticket_number);

        $this->assertNull($run->ticket_id);
        $this->assertNull($run->ticket_number);

        $this->assertNull($task->last_ticket_id);
        $this->assertNull($task->last_ticket_number);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'scheduled_znuny_attempt_manually_linked',
            'entity_type' => 'ZnunyTicketCreationAttempt',
            'entity_id' => $attempt->id,
        ]);
    }

    public function test_concurrent_same_ticket_manual_link_is_idempotent(): void
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Concurrent idempotent test',
            'enabled' => true,
            'cron_expression' => '0 0 * * *',
            'timezone' => 'UTC',
        ]);

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'status' => 'uncertain',
            'scheduled_for' => now(),
        ]);

        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $run->id,
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'marker' => 'MARKER123',
            'subject_original' => 'Original subject',
            'subject_sent' => 'Original subject [MARKER123]',
            'started_at' => now(),
        ]);

        $reader = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->expects($this->once())
            ->method('getTickets')
            ->willReturnCallback(function () use ($attempt) {
                $attempt->update([
                    'status' => ZnunyTicketCreationAttemptStatus::ManuallyLinked,
                    'ticket_id' => 20,
                    'ticket_number' => 'TN20',
                ]);

                return [[
                    'TicketID' => 20,
                    'TicketNumber' => 'TN20',
                    'Title' => 'Notification for [MARKER123] issue',
                    'StateType' => 'open',
                ]];
            });

        $linkService = $this->registerDependenciesAndGetService($reader);

        $result = $linkService->link($attempt->id, 20, 'TN20');

        $this->assertTrue($result['linked']);
        $this->assertFalse($result['transitioned']);
        $this->assertSame('manually_linked', $result['attempt_status'] ?? null);

        $attempt->refresh();
        $this->assertSame(ZnunyTicketCreationAttemptStatus::ManuallyLinked, $attempt->status);
        $this->assertSame(20, (int) $attempt->ticket_id);
        $this->assertSame('TN20', $attempt->ticket_number);

        $run->refresh();
        $task->refresh();
        $this->assertNull($run->ticket_id);
        $this->assertNull($run->ticket_number);
        $this->assertNull($task->last_ticket_id);
        $this->assertNull($task->last_ticket_number);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'scheduled_znuny_attempt_manually_linked',
            'entity_type' => 'ZnunyTicketCreationAttempt',
            'entity_id' => $attempt->id,
        ]);
    }

    public function test_ticket_not_present_in_lookup_is_rejected_without_mutation(): void
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Ticket absent test',
            'enabled' => true,
            'cron_expression' => '0 0 * * *',
            'timezone' => 'UTC',
        ]);

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'status' => 'uncertain',
            'scheduled_for' => now(),
        ]);

        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $run->id,
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'marker' => 'MARKER123',
            'subject_original' => 'Original subject',
            'subject_sent' => 'Original subject [MARKER123]',
            'started_at' => now(),
        ]);

        $reader = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->expects($this->once())
            ->method('getTickets')
            ->willReturn([[
                'TicketID' => 10,
                'TicketNumber' => 'TN10',
                'Title' => 'Notification for [MARKER123] issue',
                'StateType' => 'open',
            ]]);

        $linkService = $this->registerDependenciesAndGetService($reader);

        $result = $linkService->link($attempt->id, 20, 'TN20');

        $this->assertFalse($result['linked']);
        $this->assertFalse($result['transitioned']);
        $this->assertSame($attempt->id, $result['attempt_id']);
        $this->assertSame($run->id, $result['run_id']);
        $this->assertSame($task->id, $result['task_id']);
        $this->assertNull($result['ticket_id']);
        $this->assertNull($result['ticket_number']);
        $this->assertSame(__('scheduled_znuny_task_runs.review.actions.manual_link.errors.not_in_lookup'), $result['reason']);

        $attempt->refresh();
        $run->refresh();
        $task->refresh();

        $this->assertSame(ZnunyTicketCreationAttemptStatus::Uncertain, $attempt->status);
        $this->assertNull($attempt->ticket_id);
        $this->assertNull($attempt->ticket_number);

        $this->assertNull($run->ticket_id);
        $this->assertNull($run->ticket_number);

        $this->assertNull($task->last_ticket_id);
        $this->assertNull($task->last_ticket_number);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'scheduled_znuny_attempt_manually_linked',
            'entity_type' => 'ZnunyTicketCreationAttempt',
            'entity_id' => $attempt->id,
        ]);
    }

    public function test_exact_ticket_can_be_selected_from_multiple_marker_matches(): void
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Multiple matches test',
            'enabled' => true,
            'cron_expression' => '0 0 * * *',
            'timezone' => 'UTC',
        ]);

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'status' => 'uncertain',
            'scheduled_for' => now(),
        ]);

        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $run->id,
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'marker' => 'MARKER123',
            'subject_original' => 'Original subject',
            'subject_sent' => 'Original subject [MARKER123]',
            'started_at' => now(),
        ]);

        $reader = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->expects($this->once())
            ->method('getTickets')
            ->willReturn([
                [
                    'TicketID' => 10,
                    'TicketNumber' => 'TN10',
                    'Title' => 'First for [MARKER123] issue',
                    'StateType' => 'open',
                ],
                [
                    'TicketID' => 20,
                    'TicketNumber' => 'TN20',
                    'Title' => 'Second for [MARKER123] issue',
                    'StateType' => 'new',
                ],
            ]);

        $linkService = $this->registerDependenciesAndGetService($reader);

        $result = $linkService->link($attempt->id, 20, 'TN20');

        $this->assertSame('Multiple', $result['lookup_status']->name ?? null);
        $this->assertTrue($result['linked']);
        $this->assertTrue($result['transitioned']);
        $this->assertSame('manually_linked', $result['attempt_status'] ?? null);
        $this->assertSame(20, (int) $result['ticket_id']);
        $this->assertSame('TN20', $result['ticket_number']);

        $attempt->refresh();
        $run->refresh();
        $task->refresh();

        $this->assertSame(ZnunyTicketCreationAttemptStatus::ManuallyLinked, $attempt->status);
        $this->assertSame(20, (int) $attempt->ticket_id);
        $this->assertSame('TN20', $attempt->ticket_number);

        $this->assertSame(20, (int) $run->ticket_id);
        $this->assertSame('TN20', $run->ticket_number);

        $this->assertSame(20, (int) $task->last_ticket_id);
        $this->assertSame('TN20', $task->last_ticket_number);

        $this->assertSame('uncertain', $run->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'scheduled_znuny_attempt_manually_linked',
            'entity_type' => 'ZnunyTicketCreationAttempt',
            'entity_id' => $attempt->id,
        ]);

        $this->assertSame(1, AuditLog::where('action', 'scheduled_znuny_attempt_manually_linked')
            ->where('entity_id', $attempt->id)->count());
    }

    public function test_attempt_changed_after_lookup_is_not_overwritten(): void
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'State conflict test',
            'enabled' => true,
            'cron_expression' => '0 0 * * *',
            'timezone' => 'UTC',
        ]);

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'status' => 'uncertain',
            'scheduled_for' => now(),
        ]);

        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $run->id,
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'marker' => 'MARKER123',
            'subject_original' => 'Original subject',
            'subject_sent' => 'Original subject [MARKER123]',
            'started_at' => now(),
        ]);

        $reader = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->expects($this->once())
            ->method('getTickets')
            ->willReturnCallback(function () use ($attempt) {
                $attempt->update([
                    'status' => ZnunyTicketCreationAttemptStatus::Recovered,
                    'ticket_id' => 30,
                    'ticket_number' => 'TN30',
                ]);

                return [[
                    'TicketID' => 20,
                    'TicketNumber' => 'TN20',
                    'Title' => 'Notification for [MARKER123] issue',
                    'StateType' => 'open',
                ]];
            });

        $linkService = $this->registerDependenciesAndGetService($reader);

        $result = $linkService->link($attempt->id, 20, 'TN20');

        $this->assertFalse($result['linked']);
        $this->assertFalse($result['transitioned']);
        $this->assertSame(__('scheduled_znuny_task_runs.review.actions.manual_link.errors.attempt_changed'), $result['reason']);

        $attempt->refresh();
        $run->refresh();
        $task->refresh();

        $this->assertSame(ZnunyTicketCreationAttemptStatus::Recovered, $attempt->status);
        $this->assertSame(30, (int) $attempt->ticket_id);
        $this->assertSame('TN30', $attempt->ticket_number);

        $this->assertNull($run->ticket_id);
        $this->assertNull($run->ticket_number);
        $this->assertNull($task->last_ticket_id);
        $this->assertNull($task->last_ticket_number);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'scheduled_znuny_attempt_manually_linked',
            'entity_type' => 'ZnunyTicketCreationAttempt',
            'entity_id' => $attempt->id,
        ]);
    }

    public function test_already_linked_same_ticket_is_idempotent_without_lookup(): void
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Same ticket idempotency',
            'enabled' => true,
            'cron_expression' => '0 0 * * *',
            'timezone' => 'UTC',
            'last_status' => 'success',
            'last_ticket_id' => 20,
            'last_ticket_number' => 'TN20',
        ]);

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'status' => 'uncertain',
            'scheduled_for' => now(),
            'ticket_id' => 20,
            'ticket_number' => 'TN20',
            'resolved_at' => now()->subMinute(),
            'resolution_type' => 'manual_link',
        ]);

        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $run->id,
            'status' => ZnunyTicketCreationAttemptStatus::ManuallyLinked,
            'marker' => 'MARKER-IDEMPOTENT',
            'subject_original' => 'Original subject',
            'subject_sent' => 'Original subject [MARKER-IDEMPOTENT]',
            'ticket_id' => 20,
            'ticket_number' => 'TN20',
            'started_at' => now()->subMinutes(2),
            'finished_at' => now()->subMinute(),
        ]);

        $reader = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->expects($this->never())->method('getTickets');

        $kernel = $this->createMock(Kernel::class);
        $kernel->expects($this->never())->method('call');

        $attemptUpdatedAt = $attempt->updated_at->toDateTimeString();
        $runUpdatedAt = $run->updated_at->toDateTimeString();
        $taskUpdatedAt = $task->updated_at->toDateTimeString();
        $resolvedAt = $run->resolved_at->toDateTimeString();
        $finishedAt = $attempt->finished_at->toDateTimeString();

        $result = $this->registerDependenciesAndGetService($reader, $kernel)
            ->link($attempt->id, 20, ' TN20 ');

        $this->assertTrue($result['linked']);
        $this->assertFalse($result['transitioned']);
        $this->assertNull($result['reason']);

        $attempt->refresh();
        $run->refresh();
        $task->refresh();

        $this->assertSame($attemptUpdatedAt, $attempt->updated_at->toDateTimeString());
        $this->assertSame($runUpdatedAt, $run->updated_at->toDateTimeString());
        $this->assertSame($taskUpdatedAt, $task->updated_at->toDateTimeString());
        $this->assertSame($finishedAt, $attempt->finished_at->toDateTimeString());
        $this->assertSame($resolvedAt, $run->resolved_at->toDateTimeString());
        $this->assertSame('manual_link', $run->resolution_type);
        $this->assertSame(0, AuditLog::where('action', 'scheduled_znuny_attempt_manually_linked')->count());
    }

    public function test_already_linked_different_ticket_is_rejected_without_lookup(): void
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Different ticket conflict',
            'enabled' => true,
            'cron_expression' => '0 0 * * *',
            'timezone' => 'UTC',
        ]);

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'status' => 'uncertain',
            'scheduled_for' => now(),
        ]);

        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $run->id,
            'status' => ZnunyTicketCreationAttemptStatus::ManuallyLinked,
            'marker' => 'MARKER-CONFLICT',
            'subject_original' => 'Original subject',
            'subject_sent' => 'Original subject [MARKER-CONFLICT]',
            'ticket_id' => 20,
            'ticket_number' => 'TN20',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $reader = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->expects($this->never())->method('getTickets');

        $kernel = $this->createMock(Kernel::class);
        $kernel->expects($this->never())->method('call');

        $result = $this->registerDependenciesAndGetService($reader, $kernel)
            ->link($attempt->id, 30, 'TN30');

        $this->assertFalse($result['linked']);
        $this->assertFalse($result['transitioned']);
        $this->assertSame(
            __('scheduled_znuny_task_runs.review.actions.manual_link.errors.already_linked_different'),
            $result['reason'],
        );

        $attempt->refresh();
        $this->assertSame(20, (int) $attempt->ticket_id);
        $this->assertSame('TN20', $attempt->ticket_number);
        $this->assertSame(0, AuditLog::where('action', 'scheduled_znuny_attempt_manually_linked')->count());
    }

    public static function terminalStateProvider(): array
    {
        return [
            'success' => [ZnunyTicketCreationAttemptStatus::Success],
            'recovered' => [ZnunyTicketCreationAttemptStatus::Recovered],
            'resolved_without_ticket' => [ZnunyTicketCreationAttemptStatus::ResolvedWithoutTicket],
            'confirmed_failed' => [ZnunyTicketCreationAttemptStatus::ConfirmedFailed],
        ];
    }

    #[DataProvider('terminalStateProvider')]
    public function test_terminal_state_is_rejected_before_lookup(
        ZnunyTicketCreationAttemptStatus $status,
    ): void {
        $task = ScheduledZnunyTask::create([
            'name' => 'Terminal status rejection',
            'enabled' => true,
            'cron_expression' => '0 0 * * *',
            'timezone' => 'UTC',
        ]);

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'status' => 'uncertain',
            'scheduled_for' => now(),
        ]);

        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $run->id,
            'status' => $status,
            'marker' => 'MARKER-TERMINAL',
            'subject_original' => 'Original subject',
            'subject_sent' => 'Original subject [MARKER-TERMINAL]',
            'started_at' => now(),
        ]);

        $reader = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->expects($this->never())->method('getTickets');

        $kernel = $this->createMock(Kernel::class);
        $kernel->expects($this->never())->method('call');

        $result = $this->registerDependenciesAndGetService($reader, $kernel)
            ->link($attempt->id, 40, 'TN40');

        $this->assertFalse($result['linked']);
        $this->assertFalse($result['transitioned']);
        $this->assertSame(
            __('scheduled_znuny_task_runs.review.actions.manual_link.errors.terminal_state'),
            $result['reason'],
        );

        $attempt->refresh();
        $this->assertSame($status, $attempt->status);
        $this->assertNull($attempt->ticket_id);
        $this->assertSame(0, AuditLog::where('action', 'scheduled_znuny_attempt_manually_linked')->count());
    }

    public function test_run_resolved_after_lookup_is_not_overwritten(): void
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Resolved run conflict',
            'enabled' => true,
            'cron_expression' => '0 0 * * *',
            'timezone' => 'UTC',
        ]);

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'status' => 'uncertain',
            'scheduled_for' => now(),
        ]);

        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $run->id,
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'marker' => 'MARKER-RESOLVED',
            'subject_original' => 'Original subject',
            'subject_sent' => 'Original subject [MARKER-RESOLVED]',
            'started_at' => now(),
        ]);

        $reader = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->expects($this->once())
            ->method('getTickets')
            ->willReturnCallback(function () use ($run) {
                $run->update([
                    'resolved_at' => now(),
                    'resolution_type' => 'retry_created',
                ]);

                return [[
                    'TicketID' => 50,
                    'TicketNumber' => 'TN50',
                    'Title' => 'Notification for [MARKER-RESOLVED] issue',
                    'StateType' => 'open',
                ]];
            });

        $result = $this->registerDependenciesAndGetService($reader)
            ->link($attempt->id, 50, 'TN50');

        $this->assertFalse($result['linked']);
        $this->assertFalse($result['transitioned']);
        $this->assertSame(
            __('scheduled_znuny_task_runs.review.actions.manual_link.errors.attempt_changed'),
            $result['reason'],
        );

        $attempt->refresh();
        $run->refresh();
        $task->refresh();

        $this->assertSame(ZnunyTicketCreationAttemptStatus::Uncertain, $attempt->status);
        $this->assertNull($attempt->ticket_id);
        $this->assertSame('retry_created', $run->resolution_type);
        $this->assertNull($run->ticket_id);
        $this->assertNull($task->last_ticket_id);
        $this->assertSame(0, AuditLog::where('action', 'scheduled_znuny_attempt_manually_linked')->count());
    }

    public function test_transaction_failure_rolls_back_all_changes_and_creates_no_audit(): void
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Transaction rollback',
            'enabled' => true,
            'cron_expression' => '0 0 * * *',
            'timezone' => 'UTC',
            'last_status' => 'failed',
            'last_error_summary' => 'Original task error',
        ]);

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'status' => 'uncertain',
            'scheduled_for' => now(),
            'error_summary' => 'Original run error',
        ]);

        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $run->id,
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'marker' => 'MARKER-ROLLBACK',
            'subject_original' => 'Original subject',
            'subject_sent' => 'Original subject [MARKER-ROLLBACK]',
            'started_at' => now(),
        ]);

        $reader = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->expects($this->once())
            ->method('getTickets')
            ->willReturn([[
                'TicketID' => 60,
                'TicketNumber' => 'TN60',
                'Title' => 'Notification for [MARKER-ROLLBACK] issue',
                'StateType' => 'open',
            ]]);

        $originalDispatcher = ScheduledZnunyTask::getEventDispatcher();
        $temporaryDispatcher = clone $originalDispatcher;
        ScheduledZnunyTask::setEventDispatcher($temporaryDispatcher);
        ScheduledZnunyTask::saving(function () {
            throw new RuntimeException('SECRET_TRANSACTION_FAILURE');
        });

        try {
            $result = $this->registerDependenciesAndGetService($reader)
                ->link($attempt->id, 60, 'TN60');
        } finally {
            ScheduledZnunyTask::setEventDispatcher($originalDispatcher);
        }

        $this->assertFalse($result['linked']);
        $this->assertFalse($result['transitioned']);
        $this->assertSame(
            __('scheduled_znuny_task_runs.review.actions.manual_link.errors.transaction_error'),
            $result['reason'],
        );
        $this->assertStringNotContainsString('SECRET_TRANSACTION_FAILURE', (string) $result['reason']);

        $attempt->refresh();
        $run->refresh();
        $task->refresh();

        $this->assertSame(ZnunyTicketCreationAttemptStatus::Uncertain, $attempt->status);
        $this->assertNull($attempt->ticket_id);
        $this->assertNull($attempt->ticket_number);
        $this->assertNull($attempt->finished_at);

        $this->assertNull($run->ticket_id);
        $this->assertNull($run->ticket_number);
        $this->assertNull($run->resolved_at);
        $this->assertNull($run->resolution_type);
        $this->assertSame('uncertain', $run->status);
        $this->assertSame('Original run error', $run->error_summary);

        $this->assertSame('failed', $task->last_status);
        $this->assertSame('Original task error', $task->last_error_summary);
        $this->assertNull($task->last_ticket_id);
        $this->assertNull($task->last_ticket_number);
        $this->assertSame(0, AuditLog::where('action', 'scheduled_znuny_attempt_manually_linked')->count());
    }

    public function test_audit_failure_does_not_roll_back_committed_manual_link(): void
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Audit failure',
            'enabled' => true,
            'cron_expression' => '0 0 * * *',
            'timezone' => 'UTC',
            'last_status' => 'failed',
            'last_error_summary' => 'Original task error',
        ]);

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'status' => 'uncertain',
            'scheduled_for' => now(),
            'error_summary' => 'Original run error',
        ]);

        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $run->id,
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'marker' => 'MARKER-AUDIT',
            'subject_original' => 'Original subject',
            'subject_sent' => 'Original subject [MARKER-AUDIT]',
            'started_at' => now(),
        ]);

        $reader = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->expects($this->once())
            ->method('getTickets')
            ->willReturn([[
                'TicketID' => 70,
                'TicketNumber' => 'TN70',
                'Title' => 'Notification for [MARKER-AUDIT] issue',
                'StateType' => 'open',
            ]]);

        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('log')
            ->once()
            ->andThrow(new RuntimeException('SECRET_AUDIT_FAILURE'));

        $result = $this->registerDependenciesAndGetService($reader, null, $audit)
            ->link($attempt->id, 70, 'TN70');

        $this->assertTrue($result['linked']);
        $this->assertTrue($result['transitioned']);
        $this->assertNull($result['reason']);

        $attempt->refresh();
        $run->refresh();
        $task->refresh();

        $this->assertSame(ZnunyTicketCreationAttemptStatus::ManuallyLinked, $attempt->status);
        $this->assertSame(70, (int) $attempt->ticket_id);
        $this->assertSame('TN70', $attempt->ticket_number);
        $this->assertNotNull($attempt->finished_at);

        $this->assertSame(70, (int) $run->ticket_id);
        $this->assertSame('TN70', $run->ticket_number);
        $this->assertSame('manual_link', $run->resolution_type);
        $this->assertNotNull($run->resolved_at);
        $this->assertSame('uncertain', $run->status);
        $this->assertSame('Original run error', $run->error_summary);

        $this->assertSame('success', $task->last_status);
        $this->assertNull($task->last_error_summary);
        $this->assertSame(70, (int) $task->last_ticket_id);
        $this->assertSame('TN70', $task->last_ticket_number);

        $this->assertSame(0, AuditLog::where('action', 'scheduled_znuny_attempt_manually_linked')->count());
    }

    private int $chainRunCounter = 0;

    private function createChainRun(ScheduledZnunyTask $task, array $overrides = []): ScheduledZnunyTaskRun
    {
        $this->chainRunCounter++;

        return ScheduledZnunyTaskRun::create(array_merge([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'status' => 'failed',
            'scheduled_for' => now()->addSeconds($this->chainRunCounter),
            'root_run_id' => null,
            'parent_run_id' => null,
            'retry_sequence' => 0,
        ], $overrides));
    }

    private function createChainAttempt(ScheduledZnunyTaskRun $run, string $status = 'uncertain'): ZnunyTicketCreationAttempt
    {
        return ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $run->id,
            'marker' => 'MARKER_CHAIN_'.$run->id,
            'status' => $status,
            'subject_original' => 'Subject',
            'subject_sent' => 'Subject [MARKER_CHAIN_'.$run->id.']',
            'started_at' => now(),
        ]);
    }

    private function prepareChainSuccess(ZnunyTicketCreationAttempt $attempt): ScheduledZnunyTicketCreationAttemptManualLinkService
    {
        $reader = $this->createStub(ZnunyTicketWorkspaceCacheReader::class);
        $reader->method('getTickets')->willReturn([[
            'TicketID' => 99,
            'TicketNumber' => 'TN99',
            'Title' => 'Notification for ['.$attempt->marker.'] issue',
            'StateType' => 'open',
        ]]);

        return $this->registerDependenciesAndGetService($reader);
    }

    public function test_valid_current_leaf_in_retry_chain_succeeds(): void
    {
        $task = ScheduledZnunyTask::create(['name' => 'T1', 'enabled' => true, 'cron_expression' => '* * * * *', 'timezone' => 'UTC']);
        $root = $this->createChainRun($task, ['status' => 'failed']);
        $leaf = $this->createChainRun($task, ['status' => 'uncertain', 'root_run_id' => $root->id, 'parent_run_id' => $root->id, 'retry_sequence' => 1]);
        $attempt = $this->createChainAttempt($leaf, 'uncertain');

        $result = $this->prepareChainSuccess($attempt)->link($attempt->id, 99, 'TN99');

        $this->assertTrue($result['linked']);
        $this->assertTrue($result['transitioned'] ?? false);

        $leaf->refresh();
        $this->assertSame('manual_link', $leaf->resolution_type);
        $this->assertNotNull($leaf->resolved_at);
        $this->assertSame(99, (int) $leaf->ticket_id);

        $rootUpdatedAt = $root->updated_at->toDateTimeString();
        $root->refresh();
        $this->assertSame($rootUpdatedAt, $root->updated_at->toDateTimeString());

        $this->assertSame(1, AuditLog::where('action', 'scheduled_znuny_attempt_manually_linked')->count());
    }

    public function test_root_with_existing_retry_child_is_rejected(): void
    {
        $task = ScheduledZnunyTask::create(['name' => 'T1', 'enabled' => true, 'cron_expression' => '* * * * *', 'timezone' => 'UTC']);
        $root = $this->createChainRun($task, ['status' => 'failed']);
        $leaf = $this->createChainRun($task, ['status' => 'uncertain', 'root_run_id' => $root->id, 'parent_run_id' => $root->id, 'retry_sequence' => 1]);

        $rootAttempt = $this->createChainAttempt($root, 'uncertain');

        $result = $this->prepareChainSuccess($rootAttempt)->link($rootAttempt->id, 99, 'TN99');

        $this->assertFalse($result['linked']);
        $this->assertFalse($result['transitioned'] ?? false);

        $rootUpdatedAt = $root->updated_at->toDateTimeString();
        $leafUpdatedAt = $leaf->updated_at->toDateTimeString();

        $root->refresh();
        $leaf->refresh();
        $this->assertSame($rootUpdatedAt, $root->updated_at->toDateTimeString());
        $this->assertSame($leafUpdatedAt, $leaf->updated_at->toDateTimeString());

        $this->assertSame(0, AuditLog::where('action', 'scheduled_znuny_attempt_manually_linked')->count());
    }

    public function test_old_historical_leaf_is_rejected(): void
    {
        $task = ScheduledZnunyTask::create(['name' => 'T1', 'enabled' => true, 'cron_expression' => '* * * * *', 'timezone' => 'UTC']);
        $root = $this->createChainRun($task, ['status' => 'failed']);
        $mid = $this->createChainRun($task, ['status' => 'failed', 'root_run_id' => $root->id, 'parent_run_id' => $root->id, 'retry_sequence' => 1]);
        $leaf = $this->createChainRun($task, ['status' => 'uncertain', 'root_run_id' => $root->id, 'parent_run_id' => $mid->id, 'retry_sequence' => 2]);

        $midAttempt = $this->createChainAttempt($mid, 'uncertain');

        $result = $this->prepareChainSuccess($midAttempt)->link($midAttempt->id, 99, 'TN99');

        $this->assertFalse($result['linked']);
        $this->assertFalse($result['transitioned'] ?? false);

        $rootUpdatedAt = $root->updated_at->toDateTimeString();
        $midUpdatedAt = $mid->updated_at->toDateTimeString();
        $leafUpdatedAt = $leaf->updated_at->toDateTimeString();

        $root->refresh();
        $mid->refresh();
        $leaf->refresh();
        $this->assertSame($rootUpdatedAt, $root->updated_at->toDateTimeString());
        $this->assertSame($midUpdatedAt, $mid->updated_at->toDateTimeString());
        $this->assertSame($leafUpdatedAt, $leaf->updated_at->toDateTimeString());

        $this->assertSame(0, AuditLog::where('action', 'scheduled_znuny_attempt_manually_linked')->count());
    }

    public function test_retry_sequence_mismatch_is_rejected(): void
    {
        $task = ScheduledZnunyTask::create(['name' => 'T1', 'enabled' => true, 'cron_expression' => '* * * * *', 'timezone' => 'UTC']);
        $root = $this->createChainRun($task, ['status' => 'failed']);
        $leaf = $this->createChainRun($task, ['status' => 'uncertain', 'root_run_id' => $root->id, 'parent_run_id' => $root->id, 'retry_sequence' => 2]);

        $attempt = $this->createChainAttempt($leaf, 'uncertain');

        $result = $this->prepareChainSuccess($attempt)->link($attempt->id, 99, 'TN99');

        $this->assertFalse($result['linked']);
        $this->assertFalse($result['transitioned'] ?? false);

        $rootUpdatedAt = $root->updated_at->toDateTimeString();
        $leafUpdatedAt = $leaf->updated_at->toDateTimeString();

        $root->refresh();
        $leaf->refresh();
        $this->assertSame($rootUpdatedAt, $root->updated_at->toDateTimeString());
        $this->assertSame($leafUpdatedAt, $leaf->updated_at->toDateTimeString());

        $this->assertSame(0, AuditLog::where('action', 'scheduled_znuny_attempt_manually_linked')->count());
    }

    public function test_wrong_root_run_id_is_rejected(): void
    {
        $task = ScheduledZnunyTask::create(['name' => 'T1', 'enabled' => true, 'cron_expression' => '* * * * *', 'timezone' => 'UTC']);
        $root = $this->createChainRun($task, ['status' => 'failed']);
        $otherRoot = $this->createChainRun($task, ['status' => 'failed']);
        $leaf = $this->createChainRun($task, ['status' => 'uncertain', 'root_run_id' => $otherRoot->id, 'parent_run_id' => $root->id, 'retry_sequence' => 1]);

        $attempt = $this->createChainAttempt($leaf, 'uncertain');

        $result = $this->prepareChainSuccess($attempt)->link($attempt->id, 99, 'TN99');

        $this->assertFalse($result['linked']);
        $this->assertFalse($result['transitioned'] ?? false);

        $rootUpdatedAt = $root->updated_at->toDateTimeString();
        $otherRootUpdatedAt = $otherRoot->updated_at->toDateTimeString();
        $leafUpdatedAt = $leaf->updated_at->toDateTimeString();

        $root->refresh();
        $otherRoot->refresh();
        $leaf->refresh();
        $this->assertSame($rootUpdatedAt, $root->updated_at->toDateTimeString());
        $this->assertSame($otherRootUpdatedAt, $otherRoot->updated_at->toDateTimeString());
        $this->assertSame($leafUpdatedAt, $leaf->updated_at->toDateTimeString());

        $this->assertSame(0, AuditLog::where('action', 'scheduled_znuny_attempt_manually_linked')->count());
    }

    public function test_chain_member_with_wrong_scheduled_task_is_rejected(): void
    {
        $taskA = ScheduledZnunyTask::create(['name' => 'T1', 'enabled' => true, 'cron_expression' => '* * * * *', 'timezone' => 'UTC']);
        $taskB = ScheduledZnunyTask::create(['name' => 'T2', 'enabled' => true, 'cron_expression' => '* * * * *', 'timezone' => 'UTC']);

        $root = $this->createChainRun($taskA, ['status' => 'failed']);
        $leaf = $this->createChainRun($taskB, ['status' => 'uncertain', 'root_run_id' => $root->id, 'parent_run_id' => $root->id, 'retry_sequence' => 1]);

        $attempt = $this->createChainAttempt($leaf, 'uncertain');

        $result = $this->prepareChainSuccess($attempt)->link($attempt->id, 99, 'TN99');

        $this->assertFalse($result['linked']);
        $this->assertFalse($result['transitioned'] ?? false);

        $rootUpdatedAt = $root->updated_at->toDateTimeString();
        $leafUpdatedAt = $leaf->updated_at->toDateTimeString();
        $attemptUpdatedAt = $attempt->updated_at->toDateTimeString();
        $taskAUpdatedAt = $taskA->updated_at->toDateTimeString();
        $taskBUpdatedAt = $taskB->updated_at->toDateTimeString();

        $root->refresh();
        $leaf->refresh();
        $attempt->refresh();
        $taskA->refresh();
        $taskB->refresh();

        $this->assertSame($rootUpdatedAt, $root->updated_at->toDateTimeString());
        $this->assertSame($leafUpdatedAt, $leaf->updated_at->toDateTimeString());
        $this->assertSame($attemptUpdatedAt, $attempt->updated_at->toDateTimeString());
        $this->assertSame($taskAUpdatedAt, $taskA->updated_at->toDateTimeString());
        $this->assertSame($taskBUpdatedAt, $taskB->updated_at->toDateTimeString());
        $this->assertSame('uncertain', $attempt->status->value);

        $this->assertSame(0, AuditLog::where('action', 'scheduled_znuny_attempt_manually_linked')->count());
    }

    public function test_parent_outside_declared_chain_is_rejected(): void
    {
        $task = ScheduledZnunyTask::create(['name' => 'T1', 'enabled' => true, 'cron_expression' => '* * * * *', 'timezone' => 'UTC']);
        $root = $this->createChainRun($task, ['status' => 'failed']);
        $otherRun = $this->createChainRun($task, ['status' => 'failed']);
        $leaf = $this->createChainRun($task, ['status' => 'uncertain', 'root_run_id' => $root->id, 'parent_run_id' => $otherRun->id, 'retry_sequence' => 1]);

        $attempt = $this->createChainAttempt($leaf, 'uncertain');

        $result = $this->prepareChainSuccess($attempt)->link($attempt->id, 99, 'TN99');

        $this->assertFalse($result['linked']);
        $this->assertFalse($result['transitioned'] ?? false);

        $rootUpdatedAt = $root->updated_at->toDateTimeString();
        $otherRunUpdatedAt = $otherRun->updated_at->toDateTimeString();
        $leafUpdatedAt = $leaf->updated_at->toDateTimeString();
        $attemptUpdatedAt = $attempt->updated_at->toDateTimeString();
        $taskUpdatedAt = $task->updated_at->toDateTimeString();

        $root->refresh();
        $otherRun->refresh();
        $leaf->refresh();
        $attempt->refresh();
        $task->refresh();

        $this->assertSame($rootUpdatedAt, $root->updated_at->toDateTimeString());
        $this->assertSame($otherRunUpdatedAt, $otherRun->updated_at->toDateTimeString());
        $this->assertSame($leafUpdatedAt, $leaf->updated_at->toDateTimeString());
        $this->assertSame($attemptUpdatedAt, $attempt->updated_at->toDateTimeString());
        $this->assertSame($taskUpdatedAt, $task->updated_at->toDateTimeString());

        $this->assertSame(0, AuditLog::where('action', 'scheduled_znuny_attempt_manually_linked')->count());
    }

    public function test_rogue_child_pointing_into_chain_is_rejected(): void
    {
        $taskA = ScheduledZnunyTask::create(['name' => 'T1', 'enabled' => true, 'cron_expression' => '* * * * *', 'timezone' => 'UTC']);
        $root = $this->createChainRun($taskA, ['status' => 'failed']);
        $leaf = $this->createChainRun($taskA, ['status' => 'uncertain', 'root_run_id' => $root->id, 'parent_run_id' => $root->id, 'retry_sequence' => 1]);

        $taskB = ScheduledZnunyTask::create(['name' => 'T2', 'enabled' => true, 'cron_expression' => '* * * * *', 'timezone' => 'UTC']);
        $rogue = $this->createChainRun($taskB, ['status' => 'failed', 'root_run_id' => null, 'parent_run_id' => $leaf->id, 'retry_sequence' => 2]);

        $attempt = $this->createChainAttempt($leaf, 'uncertain');

        $result = $this->prepareChainSuccess($attempt)->link($attempt->id, 99, 'TN99');

        $this->assertFalse($result['linked']);
        $this->assertFalse($result['transitioned'] ?? false);

        $rootUpdatedAt = $root->updated_at->toDateTimeString();
        $leafUpdatedAt = $leaf->updated_at->toDateTimeString();
        $rogueUpdatedAt = $rogue->updated_at->toDateTimeString();
        $attemptUpdatedAt = $attempt->updated_at->toDateTimeString();
        $taskAUpdatedAt = $taskA->updated_at->toDateTimeString();
        $taskBUpdatedAt = $taskB->updated_at->toDateTimeString();

        $root->refresh();
        $leaf->refresh();
        $rogue->refresh();
        $attempt->refresh();
        $taskA->refresh();
        $taskB->refresh();

        $this->assertSame($rootUpdatedAt, $root->updated_at->toDateTimeString());
        $this->assertSame($leafUpdatedAt, $leaf->updated_at->toDateTimeString());
        $this->assertSame($rogueUpdatedAt, $rogue->updated_at->toDateTimeString());
        $this->assertSame($attemptUpdatedAt, $attempt->updated_at->toDateTimeString());
        $this->assertSame($taskAUpdatedAt, $taskA->updated_at->toDateTimeString());
        $this->assertSame($taskBUpdatedAt, $taskB->updated_at->toDateTimeString());
        $this->assertSame('uncertain', $attempt->status->value);

        $this->assertSame(0, AuditLog::where('action', 'scheduled_znuny_attempt_manually_linked')->count());
    }

    public function test_excessive_retry_chain_depth_is_rejected(): void
    {
        $task = ScheduledZnunyTask::create(['name' => 'T1', 'enabled' => true, 'cron_expression' => '* * * * *', 'timezone' => 'UTC']);
        $current = $this->createChainRun($task, ['status' => 'failed']);
        $rootId = $current->id;

        for ($i = 1; $i <= ScheduledZnunyTaskRun::MAX_RETRY_CHAIN_DEPTH + 1; $i++) {
            $next = $this->createChainRun($task, ['status' => 'failed', 'root_run_id' => $rootId, 'parent_run_id' => $current->id, 'retry_sequence' => $i]);
            $current = $next;
        }

        $current->update(['status' => 'uncertain']);
        $attempt = $this->createChainAttempt($current, 'uncertain');

        $result = $this->prepareChainSuccess($attempt)->link($attempt->id, 99, 'TN99');

        $this->assertFalse($result['linked']);
        $this->assertFalse($result['transitioned'] ?? false);

        $attemptUpdatedAt = $attempt->updated_at->toDateTimeString();
        $taskUpdatedAt = $task->updated_at->toDateTimeString();

        $attempt->refresh();
        $current->refresh();
        $task->refresh();

        $this->assertSame('uncertain', $attempt->status->value);
        $this->assertSame($attemptUpdatedAt, $attempt->updated_at->toDateTimeString());
        $this->assertSame('uncertain', $current->status);
        $this->assertNull($current->ticket_id);
        $this->assertSame($taskUpdatedAt, $task->updated_at->toDateTimeString());

        $this->assertSame(0, AuditLog::where('action', 'scheduled_znuny_attempt_manually_linked')->count());
    }
}
