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

    public function test_not_sent_outcome_reverts_to_pending_and_pauses_scheduler()
    {
        $this->ticketServiceMock->expects($this->once())
            ->method('createTicketFromTask')
            ->willReturn([
                'outcome' => ScheduledTicketCreationOutcome::NOT_SENT,
                'error_summary' => 'Local validation failed',
                'error_details' => 'Missing Queue',
            ]);

        $this->mailServiceMock->expects($this->once())
            ->method('sendWarning')
            ->with(
                $this->stringContains('Scheduler Paused'),
                $this->stringContains('Local validation failed')
            );

        $processor = app(ScheduledZnunyTaskRunProcessor::class);
        $processor->processNextBatch(1, 10);

        $this->run->refresh();
        $this->assertEquals('pending', $this->run->status);
        $this->assertNull($this->run->started_at);
        $this->assertNull($this->run->finished_at);
        $this->assertNull($this->run->ticket_id);
        $this->assertTrue(app(SchedulerSafetyService::class)->isSchedulerPaused());
    }

    public function test_failed_outcome_increments_failures_and_disables_at_threshold()
    {
        Cache::put('scheduled_tasks_consecutive_failures', 2); // default threshold is 3

        $this->ticketServiceMock->expects($this->once())
            ->method('createTicketFromTask')
            ->willReturn([
                'outcome' => ScheduledTicketCreationOutcome::FAILED,
                'error_summary' => 'API failed',
                'error_details' => 'Bad credentials',
                'response_snapshot' => ['raw' => 'sanitized_failed'],
            ]);

        $this->mailServiceMock->expects($this->once())
            ->method('sendAlarm')
            ->with(
                $this->stringContains('Scheduler Disabled'),
                $this->stringContains('API failed')
            );

        $processor = app(ScheduledZnunyTaskRunProcessor::class);
        $processor->processNextBatch(1, 10);

        $this->run->refresh();
        $this->assertEquals('failed', $this->run->status);
        $this->assertNull($this->run->ticket_id);
        $this->assertEquals(['raw' => 'sanitized_failed'], $this->run->response_snapshot);
        $this->assertEquals(3, Cache::get('scheduled_tasks_consecutive_failures'));
        $this->assertFalse(app(SchedulerSafetyService::class)->isSchedulerEnabled());
    }

    public function test_uncertain_outcome_immediately_disables_scheduler()
    {
        $this->ticketServiceMock->expects($this->once())
            ->method('createTicketFromTask')
            ->willReturn([
                'outcome' => ScheduledTicketCreationOutcome::UNCERTAIN,
                'error_summary' => 'Timeout',
                'error_details' => 'cURL error 28',
                'response_snapshot' => ['raw' => 'sanitized_uncertain'],
            ]);

        $this->mailServiceMock->expects($this->once())
            ->method('sendAlarm')
            ->with(
                $this->stringContains('Scheduler Disabled'),
                $this->stringContains('Timeout')
            );

        $processor = app(ScheduledZnunyTaskRunProcessor::class);
        $processor->processNextBatch(1, 10);

        $this->run->refresh();
        $this->assertEquals('uncertain', $this->run->status);
        $this->assertNull($this->run->ticket_id);
        $this->assertEquals(['raw' => 'sanitized_uncertain'], $this->run->response_snapshot);
        $this->assertFalse(app(SchedulerSafetyService::class)->isSchedulerEnabled());
    }
}
