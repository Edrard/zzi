<?php

namespace Tests\Feature\Scheduler;

use App\Models\ScheduledZnunyTask;
use App\Services\ScheduledZnunyTicketCreationService;
use App\Services\Znuny\ScheduledTicketCreationOutcome;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketAdvancedDefaultsService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledZnunyTicketCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    private $clientMock;

    private $defaultsMock;

    private ScheduledZnunyTicketCreationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientMock = $this->createMock(ZnunyClient::class);
        $this->defaultsMock = $this->createMock(ZnunyTicketAdvancedDefaultsService::class);

        $this->service = new ScheduledZnunyTicketCreationService(
            $this->clientMock,
            $this->defaultsMock
        );
    }

    public function test_missing_local_configuration_returns_not_sent()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test',
            'enabled' => true,
            // queue_name, owner_id, customer_user_login missing
        ]);

        $this->clientMock->expects($this->never())->method('createTicket');

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::NOT_SENT, $result['outcome']);
        $this->assertStringContainsString('Missing required Owner', $result['error_summary']);
    }

    public function test_missing_subject_returns_not_sent()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test',
            'enabled' => true,
            'queue_name' => 'Q1',
            'owner_id' => 1,
            'customer_user_login' => 'user1',
            // subject, body missing
        ]);

        $this->clientMock->expects($this->never())->method('createTicket');

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::NOT_SENT, $result['outcome']);
        $this->assertStringContainsString('Ticket title and article body are required', $result['error_summary']);
    }

    public function test_customer_user_not_found_returns_not_sent()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test',
            'enabled' => true,
            'queue_name' => 'Q1',
            'owner_id' => 1,
            'customer_user_login' => 'user1',
            'subject' => 'Sub',
            'body' => 'Body',
        ]);

        $this->clientMock->expects($this->once())
            ->method('getCustomerUser')
            ->with('user1')
            ->willReturn(['found' => false]);

        $this->clientMock->expects($this->never())->method('createTicket');

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::NOT_SENT, $result['outcome']);
        $this->assertStringContainsString('Failed to resolve CustomerUser', $result['error_summary']);
    }

    public function test_validate_ticket_create_invalid_returns_failed()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test',
            'enabled' => true,
            'queue_name' => 'Q1',
            'owner_id' => 1,
            'customer_user_login' => 'user1',
            'subject' => 'Sub',
            'body' => 'Body',
        ]);

        $this->clientMock->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);

        $this->clientMock->expects($this->once())
            ->method('validateTicketCreate')
            ->willReturn(['valid' => false, 'errors' => ['Queue not active']]);

        $this->clientMock->expects($this->never())->method('createTicket');

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::FAILED, $result['outcome']);
        $this->assertStringContainsString('validation failed', $result['error_summary']);
    }

    public function test_validate_ticket_create_throws_returns_not_sent()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test',
            'enabled' => true,
            'queue_name' => 'Q1',
            'owner_id' => 1,
            'customer_user_login' => 'user1',
            'subject' => 'Sub',
            'body' => 'Body',
        ]);

        $this->clientMock->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);

        $this->clientMock->expects($this->once())
            ->method('validateTicketCreate')
            ->willThrowException(new Exception('Network Error'));

        $this->clientMock->expects($this->never())->method('createTicket');

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::NOT_SENT, $result['outcome']);
        $this->assertStringContainsString('Pre-flight check failed: Network Error', $result['error_summary']);
    }

    public function test_create_ticket_success_returns_success_and_sanitizes_snapshot()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test',
            'enabled' => true,
            'queue_name' => 'Q1',
            'owner_id' => 1,
            'customer_user_login' => 'user1',
            'subject' => 'Sub',
            'body' => 'Body',
        ]);

        $this->clientMock->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->method('validateTicketCreate')->willReturn(['valid' => true]);

        $this->clientMock->expects($this->once())
            ->method('createTicket')
            ->with($this->callback(function ($payload) {
                return $payload['Ticket']['Queue'] === 'Q1' &&
                       $payload['Ticket']['Lock'] === 'lock' &&
                       $payload['Article']['Body'] === 'Body';
            }))
            ->willReturn([
                'success' => true,
                'ticket_id' => 42,
                'ticket_number' => 'TN42',
                'raw' => [
                    'SessionID' => 'secret_session',
                    'Other' => 'safe_data',
                ],
            ]);

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::SUCCESS, $result['outcome']);
        $this->assertEquals(42, $result['ticket_id']);
        $this->assertEquals('TN42', $result['ticket_number']);
        $this->assertEquals('[REDACTED]', $result['response_snapshot']['SessionID']);
        $this->assertEquals('safe_data', $result['response_snapshot']['Other']);
    }

    public function test_create_ticket_false_returns_uncertain()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test',
            'enabled' => true,
            'queue_name' => 'Q1',
            'owner_id' => 1,
            'customer_user_login' => 'user1',
            'subject' => 'Sub',
            'body' => 'Body',
        ]);

        $this->clientMock->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->method('validateTicketCreate')->willReturn(['valid' => true]);

        $this->clientMock->expects($this->once())
            ->method('createTicket')
            ->willReturn([
                'success' => false,
                'errors' => ['Something went wrong'],
            ]);

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::UNCERTAIN, $result['outcome']);
        $this->assertStringContainsString('missing ID/Number', $result['error_summary']);
    }

    public function test_create_ticket_throws_returns_uncertain()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test',
            'enabled' => true,
            'queue_name' => 'Q1',
            'owner_id' => 1,
            'customer_user_login' => 'user1',
            'subject' => 'Sub',
            'body' => 'Body',
        ]);

        $this->clientMock->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->method('validateTicketCreate')->willReturn(['valid' => true]);

        $this->clientMock->expects($this->once())
            ->method('createTicket')
            ->willThrowException(new Exception('Timeout Exception'));

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::UNCERTAIN, $result['outcome']);
        $this->assertStringContainsString('Exception during ticket creation HTTP request: Timeout Exception', $result['error_summary']);
    }

    public function test_create_ticket_uses_task_lock_override()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test',
            'enabled' => true,
            'queue_name' => 'Q1',
            'owner_id' => 1,
            'customer_user_login' => 'user1',
            'subject' => 'Sub',
            'body' => 'Body',
            'lock_name' => 'unlock',
        ]);

        $this->clientMock->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->method('validateTicketCreate')->willReturn(['valid' => true]);

        $this->clientMock->expects($this->once())
            ->method('createTicket')
            ->with($this->callback(function ($payload) {
                return $payload['Ticket']['Lock'] === 'unlock';
            }))
            ->willReturn([
                'success' => true,
                'ticket_id' => 42,
                'ticket_number' => 'TN42',
            ]);

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::SUCCESS, $result['outcome']);
    }
}
