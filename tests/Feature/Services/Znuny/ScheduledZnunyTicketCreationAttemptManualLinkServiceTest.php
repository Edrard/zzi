<?php

namespace Tests\Feature\Services\Znuny;

use App\Enums\ZnunyTicketCreationAttemptStatus;
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
use Tests\TestCase;

final class ScheduledZnunyTicketCreationAttemptManualLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    private function registerDependenciesAndGetService(ZnunyTicketWorkspaceCacheReader $reader, ?Kernel $kernel = null): ScheduledZnunyTicketCreationAttemptManualLinkService
    {
        $audit = new AuditLogger();

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
        $this->assertSame(10, (int) $run->ticket_id);
        $this->assertSame('TN10', $run->ticket_number);
        $this->assertSame(10, (int) $task->last_ticket_id);
        $this->assertSame('TN10', $task->last_ticket_number);
        
        $this->assertSame('uncertain', $run->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'scheduled_znuny_attempt_manually_linked',
            'entity_type' => 'ZnunyTicketCreationAttempt',
            'entity_id' => $attempt->id,
        ]);
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
}
