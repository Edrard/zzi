<?php

namespace Tests\Feature\Scheduler;

use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Services\MailNotificationService;
use App\Services\ScheduledZnunyTaskRunProcessor;
use App\Services\ScheduledZnunyTicketCreationService;
use App\Services\SchedulerSafetyService;
use App\Services\Znuny\ScheduledTicketCreationOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ScheduledZnunyTaskRunProcessorTest extends TestCase
{
    use RefreshDatabase;

    private ScheduledZnunyTask $task;

    private ScheduledZnunyTaskRun $run;

    private ScheduledZnunyTicketCreationService $ticketServiceMock;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('db:seed', ['--class' => 'SettingsSeeder']);

        $this->task = ScheduledZnunyTask::create([
            'name' => 'Test Task',
            'enabled' => true,
            'cron_expression' => '0 0 * * *',
            'timezone' => 'UTC',
        ]);

        $this->run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $this->task->id,
            'task_name_snapshot' => $this->task->name,
            'run_type' => 'scheduled',
            'status' => 'pending',
            'scheduled_for' => now(),
        ]);

        $this->ticketServiceMock = $this->createMock(ScheduledZnunyTicketCreationService::class);
        $this->app->instance(ScheduledZnunyTicketCreationService::class, $this->ticketServiceMock);

        $mailServiceStub = $this->createStub(MailNotificationService::class);
        $this->app->instance(MailNotificationService::class, $mailServiceStub);

        $this->alertServiceStub = $this->createStub(\App\Services\SystemAlertService::class);
        $this->app->instance(\App\Services\SystemAlertService::class, $this->alertServiceStub);

        Cache::forget('scheduled_tasks_consecutive_failures');
        app(SchedulerSafetyService::class)->enableScheduler();
        \App\Services\SettingsService::clearAllCaches();
    }

    public function test_success_outcome_marks_run_and_task_as_success()
    {
        Cache::put('scheduled_tasks_consecutive_failures', 2);

        $this->ticketServiceMock->expects($this->once())
            ->method('createTicketFromTask')
            ->willReturn([
                'outcome' => ScheduledTicketCreationOutcome::SUCCESS,
                'ticket_id' => 123,
                'ticket_number' => 'TN123',
                'error_summary' => null,
                'error_details' => null,
                'response_snapshot' => ['raw' => 'sanitized_success'],
            ]);



        $processor = app(ScheduledZnunyTaskRunProcessor::class);
        $processor->processNextBatch(1, 10);

        $this->run->refresh();
        $this->task->refresh();

        $this->assertEquals('success', $this->run->status);
        $this->assertEquals(123, $this->run->ticket_id);
        $this->assertEquals('TN123', $this->run->ticket_number);
        $this->assertEquals(['raw' => 'sanitized_success'], $this->run->response_snapshot);

        $this->assertEquals('success', $this->task->last_status);
        $this->assertEquals(123, $this->task->last_ticket_id);
        $this->assertEquals('TN123', $this->task->last_ticket_number);
        $this->assertNull(Cache::get('scheduled_tasks_consecutive_failures'));
    }

    public function test_not_sent_outcome_fails_task_and_does_not_pause_scheduler()
    {
        $this->ticketServiceMock->expects($this->once())
            ->method('createTicketFromTask')
            ->willReturn([
                'outcome' => ScheduledTicketCreationOutcome::NOT_SENT,
                'error_summary' => 'Local validation failed',
                'error_details' => 'Missing Queue',
            ]);



        $processor = app(ScheduledZnunyTaskRunProcessor::class);
        $processor->processNextBatch(1, 10);

        $this->run->refresh();
        $this->task->refresh();

        $this->assertEquals('failed', $this->run->status);
        $this->assertEquals('failed', $this->task->last_status);
        $this->assertEquals('Local validation failed', $this->task->last_error_summary);
        $this->assertTrue(app(SchedulerSafetyService::class)->isSchedulerEnabled());
    }

    public function test_failed_outcome_increments_consecutive_failures_and_disables_scheduler_at_threshold()
    {
        $this->ticketServiceMock->expects($this->once())
            ->method('createTicketFromTask')
            ->willReturn([
                'outcome' => ScheduledTicketCreationOutcome::FAILED,
                'error_summary' => 'API Error',
                'error_details' => 'Timeout',
                'response_snapshot' => ['raw' => 'sanitized_error'],
            ]);

        $mailServiceMock = $this->createMock(MailNotificationService::class);
        $mailServiceMock->expects($this->once())
            ->method('sendAlarm')
            ->with(
                'Scheduler Disabled (Failure Threshold)',
                $this->stringContains('API Error')
            );
        $this->app->instance(MailNotificationService::class, $mailServiceMock);

        Cache::put('scheduled_tasks_consecutive_failures', 2);
        $processor = app(ScheduledZnunyTaskRunProcessor::class);
        $processor->processNextBatch(1, 10);

        $this->run->refresh();
        $this->task->refresh();

        $this->assertEquals('failed', $this->run->status);
        $this->assertEquals(['raw' => 'sanitized_error'], $this->run->response_snapshot);
        $this->assertEquals(3, Cache::get('scheduled_tasks_consecutive_failures'));
        $this->assertFalse(app(SchedulerSafetyService::class)->isSchedulerEnabled());
    }

    public function test_uncertain_outcome_disables_scheduler()
    {
        $this->task->update(['last_ticket_id' => 777, 'last_ticket_number' => 'TN777']);

        $this->ticketServiceMock->expects($this->once())
            ->method('createTicketFromTask')
            ->willReturn([
                'outcome' => ScheduledTicketCreationOutcome::UNCERTAIN,
                'error_summary' => 'Connection dropped after payload sent',
                'error_details' => 'EOF',
                'response_snapshot' => ['raw' => 'sanitized_uncertain'],
            ]);

        $mailServiceMock = $this->createMock(MailNotificationService::class);
        $mailServiceMock->expects($this->once())
            ->method('sendAlarm')
            ->with(
                'Scheduler Disabled (Uncertain Outcome)',
                $this->stringContains('Connection dropped after payload sent')
            );
        $this->app->instance(MailNotificationService::class, $mailServiceMock);

        $processor = app(ScheduledZnunyTaskRunProcessor::class);
        $processor->processNextBatch(1, 10);

        $this->run->refresh();
        $this->task->refresh();

        $this->assertEquals('uncertain', $this->run->status);
        $this->assertNull($this->run->ticket_id);
        $this->assertEquals(777, $this->task->last_ticket_id);
        $this->assertEquals('TN777', $this->task->last_ticket_number);
        $this->assertEquals(['raw' => 'sanitized_uncertain'], $this->run->response_snapshot);
        $this->assertFalse(app(SchedulerSafetyService::class)->isSchedulerEnabled());
    }
    public function test_normal_successful_result_remains_successful()
    {
        $this->ticketServiceMock->expects($this->once())
            ->method('createTicketFromTask')
            ->willReturn([
                'outcome' => ScheduledTicketCreationOutcome::SUCCESS,
                'duplicate' => false,
                'recovered' => false,
                'attempt_id' => 99,
                'ticket_id' => 123,
                'ticket_number' => 'TN123',
                'error_summary' => null,
                'error_details' => null,
            ]);



        $processor = app(ScheduledZnunyTaskRunProcessor::class);
        $processor->processNextBatch(1, 10);

        $this->run->refresh();
        $this->assertEquals('success', $this->run->status);
        $this->assertEquals(123, $this->run->ticket_id);
    }

    public function test_confirmed_duplicate_finishes_run_successfully_and_does_not_pause()
    {
        $this->ticketServiceMock->expects($this->once())
            ->method('createTicketFromTask')
            ->willReturn([
                'outcome' => ScheduledTicketCreationOutcome::SUCCESS,
                'duplicate' => true,
                'recovered' => false,
                'attempt_id' => 100,
                'ticket_id' => 124,
                'ticket_number' => 'TN124',
            ]);



        $processor = app(ScheduledZnunyTaskRunProcessor::class);
        $processor->processNextBatch(1, 10);

        $this->run->refresh();
        $this->assertEquals('success', $this->run->status);
        $this->assertEquals(124, $this->run->ticket_id);
        $this->assertEquals('TN124', $this->run->ticket_number);
        $this->assertTrue(app(SchedulerSafetyService::class)->isSchedulerEnabled());
    }

    public function test_recovered_duplicate_finishes_run_successfully_and_does_not_become_uncertain()
    {
        $this->ticketServiceMock->expects($this->once())
            ->method('createTicketFromTask')
            ->willReturn([
                'outcome' => ScheduledTicketCreationOutcome::SUCCESS,
                'duplicate' => true,
                'recovered' => true,
                'attempt_id' => 101,
                'ticket_id' => 125,
                'ticket_number' => 'TN125',
            ]);



        $processor = app(ScheduledZnunyTaskRunProcessor::class);
        $processor->processNextBatch(1, 10);

        $this->run->refresh();
        $this->assertEquals('success', $this->run->status);
        $this->assertEquals(125, $this->run->ticket_id);
        $this->assertEquals('TN125', $this->run->ticket_number);
        $this->assertTrue(app(SchedulerSafetyService::class)->isSchedulerEnabled());
    }

    public function test_duplicate_blocked_uncertain_marks_run_uncertain_and_preserves_identifiers()
    {
        $this->ticketServiceMock->expects($this->once())
            ->method('createTicketFromTask')
            ->willReturn([
                'outcome' => ScheduledTicketCreationOutcome::UNCERTAIN,
                'duplicate' => true,
                'recovered' => false,
                'attempt_id' => 102,
                'ticket_id' => 126,
                'ticket_number' => 'TN126',
                'error_summary' => 'Duplicate blocked',
                'error_details' => 'Blocked reason',
            ]);

        $mailServiceMock = $this->createMock(MailNotificationService::class);
        $mailServiceMock->expects($this->once())
            ->method('sendAlarm')
            ->with(
                'Scheduler Disabled (Uncertain Outcome)',
                $this->stringContains('Duplicate blocked')
            );
        $this->app->instance(MailNotificationService::class, $mailServiceMock);

        $processor = app(ScheduledZnunyTaskRunProcessor::class);
        $processor->processNextBatch(1, 10);

        $this->run->refresh();
        $this->task->refresh();
        $this->assertEquals('uncertain', $this->run->status);
        $this->assertEquals(126, $this->run->ticket_id);
        $this->assertEquals('TN126', $this->run->ticket_number);
        $this->assertEquals(126, $this->task->last_ticket_id);
        $this->assertEquals('TN126', $this->task->last_ticket_number);
        $this->assertEquals('Duplicate blocked', $this->run->error_summary);
        $this->assertEquals('Blocked reason', $this->run->error_details);
        $this->assertFalse(app(SchedulerSafetyService::class)->isSchedulerEnabled());

        // Ensure no replacement run is created
        $this->assertEquals(1, ScheduledZnunyTaskRun::count());
    }

    public function test_backward_compatibility_with_missing_keys()
    {
        $this->ticketServiceMock->expects($this->once())
            ->method('createTicketFromTask')
            ->willReturn([
                'outcome' => ScheduledTicketCreationOutcome::SUCCESS,
                'ticket_id' => 127,
                'ticket_number' => 'TN127',
                // duplicate, recovered, attempt_id omitted
            ]);



        $processor = app(ScheduledZnunyTaskRunProcessor::class);
        $processor->processNextBatch(1, 10);

        $this->run->refresh();
        $this->assertEquals('success', $this->run->status);
        $this->assertEquals(127, $this->run->ticket_id);
    }

    public function test_newly_unresolved_uncertain_run()
    {
        $this->ticketServiceMock->expects($this->once())
            ->method("createTicketFromTask")
            ->willReturn([
                "outcome" => ScheduledTicketCreationOutcome::UNCERTAIN,
                "error_summary" => "Uncertain error",
                "error_details" => "Uncertain details",
            ]);

        $alertServiceMock = $this->createMock(\App\Services\SystemAlertService::class);
        $alertServiceMock->expects($this->once())->method("danger");
        $alertServiceMock->expects($this->once())
            ->method("warning")
            ->with("scheduler", "Scheduled Znuny ticket creation requires review", $this->callback(function ($msg) {
                return str_contains($msg, "requires manual review") && str_contains($msg, $this->task->name);
            }));
        $this->app->instance(\App\Services\SystemAlertService::class, $alertServiceMock);

        $processor = app(ScheduledZnunyTaskRunProcessor::class);
        $result = $processor->processNextBatch(1, 10);

        $this->assertEquals(1, $result);

        $this->run->refresh();
        $this->assertEquals("uncertain", $this->run->status);
        $this->assertEquals("Uncertain error", $this->run->error_summary);

        $this->assertFalse(app(SchedulerSafetyService::class)->isSchedulerEnabled());
        $this->assertDatabaseHas("audit_logs", ["action" => "scheduled_znuny_run_uncertain", "entity_id" => $this->run->id]);
    }

    public function test_uncertain_with_available_identifiers()
    {
        $this->ticketServiceMock->expects($this->once())
            ->method("createTicketFromTask")
            ->willReturn([
                "outcome" => ScheduledTicketCreationOutcome::UNCERTAIN,
                "error_summary" => "Uncertain error",
                "ticket_id" => 999,
                "ticket_number" => "TN999",
            ]);

        $alertServiceMock = $this->createMock(\App\Services\SystemAlertService::class);
        $alertServiceMock->expects($this->once())->method("danger");
        $alertServiceMock->expects($this->once())->method("warning")
            ->with($this->anything(), $this->anything(), $this->callback(function ($msg) {
                return str_contains($msg, "999") && str_contains($msg, "TN999");
            }));
        $this->app->instance(\App\Services\SystemAlertService::class, $alertServiceMock);

        app(ScheduledZnunyTaskRunProcessor::class)->processNextBatch(1, 10);

        $this->run->refresh();
        $this->assertEquals(999, $this->run->ticket_id);
        $this->assertEquals("TN999", $this->run->ticket_number);
        $this->task->refresh();
        $this->assertEquals(999, $this->task->last_ticket_id);
        $this->assertDatabaseHas("audit_logs", ["action" => "scheduled_znuny_run_uncertain", "entity_id" => $this->run->id]);
    }

    public function test_uncertain_with_missing_identifiers_preserves_task_identifiers()
    {
        $this->task->update([
            "last_ticket_id" => 111,
            "last_ticket_number" => "TN111",
        ]);

        $this->ticketServiceMock->expects($this->once())
            ->method("createTicketFromTask")
            ->willReturn([
                "outcome" => ScheduledTicketCreationOutcome::UNCERTAIN,
                "error_summary" => "Uncertain error",
                "ticket_id" => null,
                "ticket_number" => "",
            ]);

        $alertServiceMock = $this->createMock(\App\Services\SystemAlertService::class);
        $alertServiceMock->expects($this->once())->method("danger");
        $alertServiceMock->expects($this->once())->method("warning")
            ->with($this->anything(), $this->anything(), $this->callback(function ($msg) {
                return str_contains($msg, "None");
            }));
        $this->app->instance(\App\Services\SystemAlertService::class, $alertServiceMock);

        app(ScheduledZnunyTaskRunProcessor::class)->processNextBatch(1, 10);

        $this->task->refresh();
        $this->assertEquals(111, $this->task->last_ticket_id);
        $this->assertEquals("TN111", $this->task->last_ticket_number);
        $this->assertDatabaseHas("audit_logs", ["action" => "scheduled_znuny_run_uncertain", "entity_id" => $this->run->id]);
    }

    public function test_already_uncertain_run_does_not_emit_side_effects()
    {
        $this->run->update(["status" => "uncertain"]);

        $this->ticketServiceMock->expects($this->never())->method("createTicketFromTask");

        $processor = app(ScheduledZnunyTaskRunProcessor::class);
        $result = $processor->processNextBatch(1, 10);

        $this->assertEquals(0, $result);

        $this->run->refresh();
        $this->assertEquals("uncertain", $this->run->status);
        $this->assertDatabaseMissing("audit_logs", ["action" => "scheduled_znuny_run_uncertain", "entity_id" => $this->run->id]);
    }

    public function test_recovered_success_does_not_emit_uncertain_events()
    {
        $this->ticketServiceMock->expects($this->once())
            ->method("createTicketFromTask")
            ->willReturn([
                "outcome" => ScheduledTicketCreationOutcome::SUCCESS,
                "recovered" => true,
                "ticket_id" => 777,
                "ticket_number" => "TN777",
            ]);

        $alertServiceMock = $this->createMock(\App\Services\SystemAlertService::class);
        $alertServiceMock->expects($this->never())->method("warning");
        $this->app->instance(\App\Services\SystemAlertService::class, $alertServiceMock);

        app(ScheduledZnunyTaskRunProcessor::class)->processNextBatch(1, 10);

        $this->run->refresh();
        $this->assertEquals("success", $this->run->status);
        $this->assertEquals(777, $this->run->ticket_id);
        $this->assertDatabaseMissing("audit_logs", ["action" => "scheduled_znuny_run_uncertain", "entity_id" => $this->run->id]);
    }

    public function test_confirmed_failure_does_not_emit_uncertain_events()
    {
        $this->ticketServiceMock->expects($this->once())
            ->method("createTicketFromTask")
            ->willReturn([
                "outcome" => ScheduledTicketCreationOutcome::FAILED,
                "error_summary" => "failed",
            ]);

        $alertServiceMock = $this->createMock(\App\Services\SystemAlertService::class);
        $alertServiceMock->expects($this->never())->method("warning");
        $this->app->instance(\App\Services\SystemAlertService::class, $alertServiceMock);

        app(ScheduledZnunyTaskRunProcessor::class)->processNextBatch(1, 10);

        $this->run->refresh();
        $this->assertEquals("failed", $this->run->status);
        $this->assertDatabaseMissing("audit_logs", ["action" => "scheduled_znuny_run_uncertain", "entity_id" => $this->run->id]);
    }

    public function test_audit_log_failure_does_not_prevent_notification_or_change_status()
    {
        $this->ticketServiceMock->expects($this->once())
            ->method("createTicketFromTask")
            ->willReturn([
                "outcome" => ScheduledTicketCreationOutcome::UNCERTAIN,
                "error_summary" => "error",
            ]);

        \App\Models\AuditLog::creating(function () {
            throw new \Exception("postgres://admin:secret@example.internal token=abc123");
        });

        $alertServiceMock = $this->createMock(\App\Services\SystemAlertService::class);
        $alertServiceMock->expects($this->once())->method("danger");
        $alertServiceMock->expects($this->once())->method("warning");
        $this->app->instance(\App\Services\SystemAlertService::class, $alertServiceMock);

        $mailServiceMock = $this->createMock(MailNotificationService::class);
        $mailServiceMock->expects($this->once())->method('sendAlarm');
        $this->app->instance(MailNotificationService::class, $mailServiceMock);

        app(ScheduledZnunyTaskRunProcessor::class)->processNextBatch(1, 10);

        $this->run->refresh();
        $this->assertEquals("uncertain", $this->run->status);
        $this->assertEquals("error", $this->run->error_summary);
        $this->assertStringNotContainsString("postgres", $this->run->error_details ?? "");
        $this->assertStringNotContainsString("abc123", $this->run->error_details ?? "");
    }

    public function test_danger_alert_failure_does_not_prevent_other_side_effects()
    {
        $this->ticketServiceMock->expects($this->once())
            ->method("createTicketFromTask")
            ->willReturn([
                "outcome" => ScheduledTicketCreationOutcome::UNCERTAIN,
                "error_summary" => "error",
            ]);

        $alertServiceMock = $this->createMock(\App\Services\SystemAlertService::class);
        $alertServiceMock->expects($this->once())
            ->method("danger")
            ->willThrowException(new \Exception("postgres://admin:secret@example.internal token=abc123"));
        $alertServiceMock->expects($this->once())->method("warning");
        $this->app->instance(\App\Services\SystemAlertService::class, $alertServiceMock);

        $mailServiceMock = $this->createMock(MailNotificationService::class);
        $mailServiceMock->expects($this->once())->method('sendAlarm');
        $this->app->instance(MailNotificationService::class, $mailServiceMock);

        app(ScheduledZnunyTaskRunProcessor::class)->processNextBatch(1, 10);

        $this->run->refresh();
        $this->assertEquals("uncertain", $this->run->status);
        $this->assertStringNotContainsString("postgres", $this->run->error_details ?? "");
        $this->assertDatabaseHas("audit_logs", ["action" => "scheduled_znuny_run_uncertain", "entity_id" => $this->run->id]);
    }

    public function test_mail_alarm_failure_does_not_prevent_other_side_effects()
    {
        $this->ticketServiceMock->expects($this->once())
            ->method("createTicketFromTask")
            ->willReturn([
                "outcome" => ScheduledTicketCreationOutcome::UNCERTAIN,
                "error_summary" => "error",
            ]);

        $alertServiceMock = $this->createMock(\App\Services\SystemAlertService::class);
        $alertServiceMock->expects($this->once())->method("danger");
        $alertServiceMock->expects($this->once())->method("warning");
        $this->app->instance(\App\Services\SystemAlertService::class, $alertServiceMock);

        $mailServiceMock = $this->createMock(MailNotificationService::class);
        $mailServiceMock->expects($this->once())
            ->method('sendAlarm')
            ->willThrowException(new \Exception("postgres://admin:secret@example.internal token=abc123"));
        $this->app->instance(MailNotificationService::class, $mailServiceMock);

        app(ScheduledZnunyTaskRunProcessor::class)->processNextBatch(1, 10);

        $this->run->refresh();
        $this->assertEquals("uncertain", $this->run->status);
        $this->assertStringNotContainsString("postgres", $this->run->error_details ?? "");
        $this->assertDatabaseHas("audit_logs", ["action" => "scheduled_znuny_run_uncertain", "entity_id" => $this->run->id]);
    }

    public function test_notification_failure_does_not_prevent_audit_log_or_change_status()
    {
        $this->ticketServiceMock->expects($this->once())
            ->method("createTicketFromTask")
            ->willReturn([
                "outcome" => ScheduledTicketCreationOutcome::UNCERTAIN,
                "error_summary" => "error",
            ]);

        $alertServiceMock = $this->createMock(\App\Services\SystemAlertService::class);
        $alertServiceMock->expects($this->once())->method("danger");
        $alertServiceMock->expects($this->once())
            ->method("warning")
            ->willThrowException(new \Exception("postgres://admin:secret@example.internal token=abc123"));
        $this->app->instance(\App\Services\SystemAlertService::class, $alertServiceMock);

        $mailServiceMock = $this->createMock(MailNotificationService::class);
        $mailServiceMock->expects($this->once())->method('sendAlarm');
        $this->app->instance(MailNotificationService::class, $mailServiceMock);

        app(ScheduledZnunyTaskRunProcessor::class)->processNextBatch(1, 10);

        $this->run->refresh();
        $this->assertEquals("uncertain", $this->run->status);
        $this->assertStringNotContainsString("postgres", $this->run->error_details ?? "");
        $this->assertDatabaseHas("audit_logs", ["action" => "scheduled_znuny_run_uncertain", "entity_id" => $this->run->id]);
    }

    public function test_repeat_invocation_maintains_status_without_duplicate_events()
    {
        $this->ticketServiceMock->expects($this->once())
            ->method("createTicketFromTask")
            ->willReturn([
                "outcome" => ScheduledTicketCreationOutcome::UNCERTAIN,
                "error_summary" => "error",
            ]);

        $alertServiceMock = $this->createMock(\App\Services\SystemAlertService::class);
        $alertServiceMock->expects($this->once())->method("danger");
        $alertServiceMock->expects($this->once())->method("warning");
        $this->app->instance(\App\Services\SystemAlertService::class, $alertServiceMock);

        $mailServiceMock = $this->createMock(MailNotificationService::class);
        $mailServiceMock->expects($this->once())->method('sendAlarm');
        $this->app->instance(MailNotificationService::class, $mailServiceMock);

        $processor = app(ScheduledZnunyTaskRunProcessor::class);
        $processor->processNextBatch(1, 10);

        $this->run->refresh();
        $this->assertEquals("uncertain", $this->run->status);

        // Assert exactly one log exists
        $this->assertEquals(1, \App\Models\AuditLog::where("action", "scheduled_znuny_run_uncertain")->where("entity_id", $this->run->id)->count());

        app(SchedulerSafetyService::class)->enableScheduler();
        \App\Services\SettingsService::clearAllCaches();

        $result = $processor->processNextBatch(1, 10);

        $this->assertEquals(0, $result);
        $this->run->refresh();
        $this->assertEquals("uncertain", $this->run->status);

        $this->assertEquals(1, \App\Models\AuditLog::where("action", "scheduled_znuny_run_uncertain")->where("entity_id", $this->run->id)->count());
    }
}
