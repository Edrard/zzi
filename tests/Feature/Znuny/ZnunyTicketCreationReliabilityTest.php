<?php

namespace Tests\Feature\Znuny;

use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Enums\ZnunyTicketCreationClassification;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\Setting;
use App\Models\User;
use App\Models\ZabbixTicket;
use App\Models\ZnunyTicketCreationAttempt;
use App\Services\ScheduledZnunyTaskRunProcessor;
use App\Services\ScheduledZnunyTicketCreationService;
use App\Services\SettingsService;
use App\Services\Znuny\ScheduledTicketCreationOutcome;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyStandaloneTicketCreationService;
use App\Services\Znuny\ZnunyTicketCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ZnunyTicketCreationReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Setup default settings
        DB::table('settings')->insert([
            ['key' => 'znuny_default_ticket_state', 'value' => 'new', 'type' => 'string'],
            ['key' => 'znuny_default_ticket_lock', 'value' => 'unlock', 'type' => 'string'],
            ['key' => 'znuny_default_ticket_priority', 'value' => '3 normal', 'type' => 'string'],
        ]);
        app(SettingsService::class)->clearAllCaches();
    }

    public function test_zabbix_origin_creation_sends_subject_ending_in_exact_stable_event_marker_and_stores_attempt()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $mockClient = $this->createMock(ZnunyClient::class);
        $mockClient->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID']);
        $mockClient->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        // Expect the createTicket call with the marked subject
        $mockClient->expects($this->once())
            ->method('createTicket')
            ->with($this->callback(function ($payload) {
                // Assert 1: During createTicket, attempt already exists and is 'Sending'
                $attempt = ZnunyTicketCreationAttempt::first();
                if (! $attempt || $attempt->status !== ZnunyTicketCreationAttemptStatus::Sending) {
                    return false;
                }

                $title = $payload['Ticket']['Title'];
                $articleSubject = $payload['Article']['Subject'];

                return str_ends_with($title, ' [ZBX:999888]') && str_ends_with($articleSubject, ' [ZBX:999888]');
            }))
            ->willReturn([
                'success' => true,
                'ticket_id' => 123,
                'ticket_number' => '1234567',
                'SessionID' => 'secret_token_123',
            ]);

        $this->app->instance(ZnunyClient::class, $mockClient);

        $service = app(ZnunyTicketCreationService::class);
        $result = $service->createTicketForProblem(
            eventId: '999888',
            hostName: 'srv1',
            problemName: 'Down',
            ownerId: 1,
            queue: 'IT',
            customerUser: 'testuser',
            title: 'Alert: Down',
            articleSubject: 'Alert: Down',
            articleBody: 'Help'
        );

        $this->assertTrue($result['success']);

        $attempt = ZnunyTicketCreationAttempt::first();
        $this->assertNotNull($attempt);
        $this->assertEquals('zabbix_problem', $attempt->source_type);
        $this->assertEquals('999888', $attempt->source_id);
        $this->assertEquals('[ZBX:999888]', $attempt->marker);
        $this->assertEquals('Alert: Down', $attempt->subject_original);
        $this->assertEquals('Alert: Down [ZBX:999888]', $attempt->subject_sent);
        $this->assertEquals($user->id, $attempt->created_by);
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Success, $attempt->status);
        $this->assertEquals(123, $attempt->ticket_id);
        $this->assertEquals('1234567', $attempt->ticket_number);
        $this->assertNotNull($attempt->payload_snapshot);
        $this->assertNotNull($attempt->finished_at);
        $this->assertEquals('[REDACTED]', $attempt->response_snapshot['SessionID']);
    }

    public function test_different_title_and_subject_result_in_identical_final_subject()
    {
        $mockClient = $this->createMock(ZnunyClient::class);
        $mockClient->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID']);
        $mockClient->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        $mockClient->expects($this->once())
            ->method('createTicket')
            ->with($this->callback(function ($payload) {
                $this->assertSame('Main title [ZBX:555]', $payload['Ticket']['Title']);
                $this->assertSame('Main title [ZBX:555]', $payload['Article']['Subject']);
                $this->assertSame($payload['Ticket']['Title'], $payload['Article']['Subject']);

                return true;
            }))
            ->willReturn([
                'success' => true,
                'ticket_id' => 999,
                'ticket_number' => 'TN999',
            ]);

        $this->app->instance(ZnunyClient::class, $mockClient);

        $service = app(ZnunyTicketCreationService::class);
        $result = $service->createTicketForProblem(
            eventId: '555',
            hostName: 'srv1',
            problemName: 'Down',
            ownerId: 1,
            queue: 'IT',
            customerUser: 'testuser',
            title: 'Main title',
            articleSubject: 'Different article subject',
            articleBody: 'Help'
        );

        $this->assertTrue($result['success']);

        $attempt = ZnunyTicketCreationAttempt::where('source_id', '555')->first();
        $this->assertNotNull($attempt);
        $this->assertSame('Main title', $attempt->subject_original);
        $this->assertSame('Main title [ZBX:555]', $attempt->subject_sent);
        $this->assertSame('Main title [ZBX:555]', $attempt->payload_snapshot['Ticket']['Title']);
        $this->assertSame('Main title [ZBX:555]', $attempt->payload_snapshot['Article']['Subject']);
    }

    public function test_scheduled_creation_uses_run_id_and_stores_payload_snapshot_and_uncertain_on_failure()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test Task',
            'queue_name' => 'IT',
            'owner_id' => 1,
            'customer_user_login' => 'testuser',
            'subject' => 'Scheduled Check',
            'body' => 'Body',
            'cron_expression' => '* * * * *',
            'enabled' => true,
        ]);

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'scheduled_for' => now(),
            'status' => 'pending',
            'started_at' => now(),
        ]);

        $mockClient = $this->createMock(ZnunyClient::class);
        $mockClient->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID']);
        $mockClient->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        // Expect the createTicket call with the marked subject using the RUN ID, not Task ID
        $mockClient->expects($this->once())
            ->method('createTicket')
            ->with($this->callback(function ($payload) use ($run) {
                return str_ends_with($payload['Ticket']['Title'], ' [SHE:'.$run->id.']');
            }))
            ->willThrowException(new \Exception('API Timeout'));

        $this->app->instance(ZnunyClient::class, $mockClient);

        $service = app(ScheduledZnunyTicketCreationService::class);
        $result = $service->createTicketFromTask($task, $run->id);

        $this->assertEquals(ScheduledTicketCreationOutcome::UNCERTAIN, $result['outcome']);

        $attempt = ZnunyTicketCreationAttempt::first();
        $this->assertNotNull($attempt);
        $this->assertEquals('scheduled_run', $attempt->source_type);
        $this->assertEquals((string) $run->id, $attempt->source_id);
        $this->assertEquals('[SHE:'.$run->id.']', $attempt->marker);
        $this->assertEquals('Scheduled Check', $attempt->subject_original);
        $this->assertEquals('Scheduled Check [SHE:'.$run->id.']', $attempt->subject_sent);
        $this->assertNull($attempt->created_by);
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Uncertain, $attempt->status);
        $this->assertNull($attempt->ticket_id);

        // Assert the payload snapshot was returned to the processor
        $this->assertNotNull($result['payload_snapshot']);
        $this->assertEquals('Scheduled Check [SHE:'.$run->id.']', $result['payload_snapshot']['Ticket']['Title']);
    }

    public function test_standalone_manual_ticket_creation_receives_no_marker_and_creates_no_attempt()
    {
        $mockClient = $this->createMock(ZnunyClient::class);
        $mockClient->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID']);
        $mockClient->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        $mockClient->expects($this->once())
            ->method('createTicket')
            ->with($this->callback(function ($payload) {
                return $payload['Ticket']['Title'] === 'Standalone Subject';
            }))
            ->willReturn(['success' => true, 'ticket_id' => 123, 'ticket_number' => '1234567']);

        $this->app->instance(ZnunyClient::class, $mockClient);

        $service = app(ZnunyStandaloneTicketCreationService::class);
        $result = $service->createTicket(
            ownerId: 1,
            queue: 'IT',
            customerUser: 'testuser',
            title: 'Standalone Subject',
            articleBody: 'Body'
        );

        $this->assertTrue($result['success']);

        $this->assertEquals(0, ZnunyTicketCreationAttempt::count());
    }

    public function test_zabbix_creation_uncertain_response()
    {
        $mockClient = $this->createMock(ZnunyClient::class);
        $mockClient->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID']);
        $mockClient->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        $mockClient->expects($this->once())
            ->method('createTicket')
            ->willReturn([
                'success' => false,
            ]);

        $this->app->instance(ZnunyClient::class, $mockClient);

        $service = app(ZnunyTicketCreationService::class);
        $result = $service->createTicketForProblem(
            eventId: '999889',
            hostName: 'srv1',
            problemName: 'Down',
            ownerId: 1,
            queue: 'IT',
            customerUser: 'testuser',
            title: 'Alert: Down',
            articleSubject: 'Alert: Down',
            articleBody: 'Help'
        );

        $this->assertFalse($result['success']);

        $attempt = ZnunyTicketCreationAttempt::first();
        $this->assertNotNull($attempt);
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Uncertain, $attempt->status);
    }

    public function test_scheduled_creation_success_uses_run_id_and_stores_snapshot()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test Task 2',
            'queue_name' => 'IT',
            'owner_id' => 1,
            'customer_user_login' => 'testuser',
            'subject' => 'Scheduled Check 2',
            'body' => 'Body',
            'cron_expression' => '* * * * *',
            'enabled' => true,
        ]);

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'scheduled_for' => now(),
            'status' => 'pending',
            'started_at' => now(),
        ]);

        $mockClient = $this->createMock(ZnunyClient::class);
        $mockClient->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID']);
        $mockClient->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        $mockClient->expects($this->once())
            ->method('createTicket')
            ->willReturn([
                'success' => true,
                'ticket_id' => 124,
                'ticket_number' => '1234568',
                'SessionID' => 'secret_token_456',
            ]);

        $this->app->instance(ZnunyClient::class, $mockClient);

        $service = app(ScheduledZnunyTicketCreationService::class);
        $result = $service->createTicketFromTask($task, $run->id);

        $this->assertEquals(ScheduledTicketCreationOutcome::SUCCESS, $result['outcome']);

        $attempt = ZnunyTicketCreationAttempt::first();
        $this->assertNotNull($attempt);
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Success, $attempt->status);
        $this->assertEquals(124, $attempt->ticket_id);
        $this->assertEquals('1234568', $attempt->ticket_number);
        $this->assertEquals('[REDACTED]', $attempt->response_snapshot['SessionID']);
    }

    public function test_scheduled_processor_uncertain_run_stores_marked_payload_snapshot()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test Task 3', 'queue_name' => 'IT', 'owner_id' => 1, 'customer_user_login' => 'testuser',
            'subject' => 'Scheduled Check 3', 'body' => 'Body', 'cron_expression' => '* * * * *', 'enabled' => true,
        ]);

        Cache::forget('scheduled_tasks_consecutive_failures');

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id, 'task_name_snapshot' => $task->name, 'run_type' => 'scheduled',
            'scheduled_for' => now(), 'status' => 'pending', 'started_at' => now(),
        ]);

        $mockClient = $this->createMock(ZnunyClient::class);
        $mockClient->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID']);

        $mockClient->expects($this->once())
            ->method('validateTicketCreate')
            ->willReturn(['valid' => true]);

        $mockClient->expects($this->once())
            ->method('createTicket')
            ->willReturn([
                'success' => false,
            ]);

        $this->app->instance(ZnunyClient::class, $mockClient);

        Setting::updateOrCreate(['key' => 'scheduled_tasks_enabled'], ['value' => 'true']);

        $processor = app(ScheduledZnunyTaskRunProcessor::class);
        $processor->processNextBatch(1, 60);

        $run->refresh();
        $task->refresh();

        $this->assertEquals('uncertain', $run->status);
        $this->assertEquals('false', Setting::where('key', 'scheduled_tasks_enabled')->value('value')); // disabled immediately
        $this->assertEquals(0, Cache::get('scheduled_tasks_consecutive_failures', 0));

        $this->assertNotNull($run->payload_snapshot);
        $this->assertTrue(str_ends_with($run->payload_snapshot['Ticket']['Title'], ' [SHE:'.$run->id.']'));

        $attempt = ZnunyTicketCreationAttempt::first();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Uncertain, $attempt->status);
    }

    public function test_scheduled_processor_explicit_api_rejection_flows_through_as_failed()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test Task 4', 'queue_name' => 'IT', 'owner_id' => 1, 'customer_user_login' => 'testuser',
            'subject' => 'Scheduled Check 4', 'body' => 'Body', 'cron_expression' => '* * * * *', 'enabled' => true,
        ]);
        Cache::forget('scheduled_tasks_consecutive_failures');
        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id, 'task_name_snapshot' => $task->name, 'run_type' => 'scheduled',
            'scheduled_for' => now(), 'status' => 'pending', 'started_at' => now(),
        ]);

        $mockClient = $this->createMock(ZnunyClient::class);
        $mockClient->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID']);

        $mockClient->expects($this->once())
            ->method('validateTicketCreate')
            ->willReturn(['valid' => true]);

        $mockClient->expects($this->once())
            ->method('createTicket')
            ->willReturn(['success' => false, 'errors' => ['API Rejection']]);

        $this->app->instance(ZnunyClient::class, $mockClient);

        Setting::updateOrCreate(['key' => 'scheduled_tasks_enabled'], ['value' => 'true']);

        $processor = app(ScheduledZnunyTaskRunProcessor::class);
        $processor->processNextBatch(1, 60);

        $run->refresh();
        $task->refresh();

        $this->assertEquals('failed', $run->status);

        $attempt = ZnunyTicketCreationAttempt::first();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::ConfirmedFailed, $attempt->status);

        $this->assertEquals(1, Cache::get('scheduled_tasks_consecutive_failures'));
        $this->assertEquals('true', Setting::where('key', 'scheduled_tasks_enabled')->value('value')); // scheduler remains enabled
    }

    public function test_zabbix_pre_flight_failure_returns_not_sent()
    {
        $mockClient = $this->createMock(ZnunyClient::class);
        $mockClient->expects($this->once())->method('getCustomerUser')->willReturn(['found' => false]);
        $mockClient->expects($this->never())->method('validateTicketCreate');
        $mockClient->expects($this->never())->method('createTicket');
        $this->app->instance(ZnunyClient::class, $mockClient);

        $service = app(ZnunyTicketCreationService::class);
        $result = $service->createTicketForProblem('111', 'srv1', 'Down', 1, 'IT', 'testuser', 'Title', 'Subject', 'Body');

        $this->assertFalse($result['success']);
        $this->assertEquals(ZnunyTicketCreationClassification::NotSent->value, $result['classification']);
        $this->assertEquals(0, ZnunyTicketCreationAttempt::count());
    }

    public function test_zabbix_explicit_api_rejection_returns_confirmed_failed()
    {
        $mockClient = $this->createMock(ZnunyClient::class);
        $mockClient->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID']);

        $mockClient->expects($this->once())
            ->method('validateTicketCreate')
            ->willReturn(['valid' => true]);

        $mockClient->expects($this->once())
            ->method('createTicket')
            ->willReturn(['success' => false, 'errors' => 'Queue is invalid']);

        $this->app->instance(ZnunyClient::class, $mockClient);

        $service = app(ZnunyTicketCreationService::class);
        $result = $service->createTicketForProblem('111', 'srv1', 'Down', 1, 'IT', 'testuser', 'Title', 'Subject', 'Body');

        $this->assertFalse($result['success']);
        $this->assertEquals(ZnunyTicketCreationClassification::ConfirmedFailed->value, $result['classification']);
        $this->assertEquals(['Queue is invalid'], $result['errors']);
        $this->assertNull($result['ticket_id']);

        $this->assertSame(0, ZabbixTicket::count());

        $attempt = ZnunyTicketCreationAttempt::first();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::ConfirmedFailed, $attempt->status);
        $this->assertStringContainsString('Queue is invalid', $attempt->error_details);
    }

    public function test_zabbix_explicit_api_rejection_with_nested_errors_sanitizes_and_normalizes()
    {
        $mockClient = $this->createMock(ZnunyClient::class);
        $mockClient->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID']);
        $mockClient->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);
        $mockClient->expects($this->once())->method('createTicket')->willReturn([
            'success' => false,
            'errors' => [
                'Error' => [
                    'Message' => 'Nested rejection',
                    'Token' => 'my-secret',
                ],
            ],
        ]);
        $this->app->instance(ZnunyClient::class, $mockClient);

        $service = app(ZnunyTicketCreationService::class);
        $result = $service->createTicketForProblem('111', 'srv1', 'Down', 1, 'IT', 'testuser', 'Title', 'Subject', 'Body');

        $this->assertFalse($result['success']);
        $this->assertEquals(ZnunyTicketCreationClassification::ConfirmedFailed->value, $result['classification']);
        $this->assertEquals(['Nested rejection', '[REDACTED]'], $result['errors']);

        $attempt = ZnunyTicketCreationAttempt::first();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::ConfirmedFailed, $attempt->status);
        $this->assertStringContainsString('Nested rejection', $attempt->error_details);
        $this->assertStringNotContainsString('my-secret', $attempt->error_details);
    }
}
