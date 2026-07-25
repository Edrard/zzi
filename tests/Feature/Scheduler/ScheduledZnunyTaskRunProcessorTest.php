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

    private MailNotificationService $mailServiceMock;

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

        $this->mailServiceMock = $this->createMock(MailNotificationService::class);
        $this->app->instance(MailNotificationService::class, $this->mailServiceMock);

        Cache::forget('scheduled_tasks_consecutive_failures');
        app(SchedulerSafetyService::class)->enableScheduler();
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

        $this->mailServiceMock->expects($this->never())->method('sendWarning');
        $this->mailServiceMock->expects($this->never())->method('sendAlarm');

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

        $this->mailServiceMock->expects($this->never())->method('sendWarning');
        $this->mailServiceMock->expects($this->never())->method('sendAlarm');

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

        $this->mailServiceMock->expects($this->once())
            ->method('sendAlarm')
            ->with(
                'Scheduler Disabled (Failure Threshold)',
                $this->stringContains('API Error')
            );

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

        $this->mailServiceMock->expects($this->once())
            ->method('sendAlarm')
            ->with(
                'Scheduler Disabled (Uncertain Outcome)',
                $this->stringContains('Connection dropped after payload sent')
            );

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

        $this->mailServiceMock->expects($this->never())->method('sendAlarm');

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

        $this->mailServiceMock->expects($this->never())->method('sendAlarm');

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

        $this->mailServiceMock->expects($this->never())->method('sendAlarm');

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

        $this->mailServiceMock->expects($this->once())
            ->method('sendAlarm')
            ->with(
                'Scheduler Disabled (Uncertain Outcome)',
                $this->stringContains('Duplicate blocked')
            );

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

        $this->mailServiceMock->expects($this->never())->method('sendAlarm');

        $processor = app(ScheduledZnunyTaskRunProcessor::class);
        $processor->processNextBatch(1, 10);

        $this->run->refresh();
        $this->assertEquals('success', $this->run->status);
        $this->assertEquals(127, $this->run->ticket_id);
    }
}
