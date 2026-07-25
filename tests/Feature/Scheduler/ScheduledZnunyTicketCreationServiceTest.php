<?php

namespace Tests\Feature\Scheduler;

use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Enums\ZnunyTicketCreationClassification;
use App\Models\ScheduledZnunyTask;
use App\Models\ZnunyTicketCreationAttempt;
use App\Services\ScheduledZnunyTicketCreationService;
use App\Services\Znuny\ScheduledTicketCreationOutcome;
use App\Services\Znuny\ScheduledZnunyTicketCreationDuplicateGuard;
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

        $this->service = $this->buildService();
    }

    private function buildService($cacheReaderMock = null, $consoleMock = null): ScheduledZnunyTicketCreationService
    {
        $duplicateGuard = new ScheduledZnunyTicketCreationDuplicateGuard();
        $reader = $cacheReaderMock ?? $this->createStub(\App\Services\Znuny\ZnunyTicketWorkspaceCacheReader::class);
        $console = $consoleMock ?? $this->createStub(\Illuminate\Contracts\Console\Kernel::class);

        $lookupService = new \App\Services\Znuny\ScheduledZnunyTicketMarkerLookupService($reader);
        $refreshLookupService = new \App\Services\Znuny\ScheduledZnunyTicketMarkerRefreshLookupService($lookupService, $console);
        $reconciliationService = new \App\Services\Znuny\ScheduledZnunyTicketCreationAttemptReconciliationService($refreshLookupService);

        return new ScheduledZnunyTicketCreationService(
            $this->clientMock,
            $this->defaultsMock,
            $duplicateGuard,
            $reconciliationService
        );
    }

    public function test_missing_local_configuration_returns_not_sent()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test',
            'enabled' => true,
            // queue_name, owner_id, customer_user_login missing
        ]);

        $this->defaultsMock->expects($this->never())->method('getDefaults');
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

        $this->defaultsMock->expects($this->never())->method('getDefaults');
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

        $this->defaultsMock->expects($this->never())->method('getDefaults');
        $this->clientMock->expects($this->never())->method('createTicket');

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::NOT_SENT, $result['outcome']);
        $this->assertStringContainsString('Failed to resolve CustomerUser', $result['error_summary']);
    }

    public function test_validate_ticket_create_invalid_returns_not_sent()
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

        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);

        $this->clientMock->expects($this->once())
            ->method('validateTicketCreate')
            ->willReturn(['valid' => false, 'errors' => ['Queue not active']]);

        $this->clientMock->expects($this->never())->method('createTicket');

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::NOT_SENT, $result['outcome']);
        $this->assertEquals(ZnunyTicketCreationClassification::NotSent->value, $result['classification']);
        $this->assertStringContainsString('validation failed', $result['error_summary']);
        $this->assertEquals(0, ZnunyTicketCreationAttempt::count());
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

        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);

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

        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

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

        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        $this->clientMock->expects($this->once())
            ->method('createTicket')
            ->willReturn([
                'success' => false,
            ]);

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::UNCERTAIN, $result['outcome']);
        $this->assertStringContainsString('Failed to refresh the active Znuny Ticket Workspace cache.', $result['error_summary']);
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

        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        $this->clientMock->expects($this->once())
            ->method('createTicket')
            ->willThrowException(new Exception('Timeout Exception'));

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::UNCERTAIN, $result['outcome']);
        $this->assertStringContainsString('Failed to refresh the active Znuny Ticket Workspace cache.', $result['error_summary']);
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

        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

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

    public function test_explicit_api_rejection_returns_confirmed_failed()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test', 'enabled' => true, 'queue_name' => 'Q1',
            'owner_id' => 1, 'customer_user_login' => 'user1',
            'subject' => 'Sub', 'body' => 'Body',
        ]);

        $this->clientMock->expects($this->once())
            ->method('getCustomerUser')
            ->willReturn(['found' => true, 'customer_id' => 'CID1']);

        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);

        $this->clientMock->expects($this->once())
            ->method('validateTicketCreate')
            ->willReturn(['valid' => true]);

        $this->clientMock->expects($this->once())
            ->method('createTicket')
            ->willReturn(['success' => false, 'errors' => ['Queue rejected']]);

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::FAILED, $result['outcome']);
        $this->assertEquals(ZnunyTicketCreationClassification::ConfirmedFailed->value, $result['classification']);
        $attempt = ZnunyTicketCreationAttempt::first();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::ConfirmedFailed, $attempt->status);
    }

    public function test_incomplete_success_returns_uncertain()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test', 'enabled' => true, 'queue_name' => 'Q1',
            'owner_id' => 1, 'customer_user_login' => 'user1',
            'subject' => 'Sub', 'body' => 'Body',
        ]);

        $this->clientMock->expects($this->once())
            ->method('getCustomerUser')
            ->willReturn(['found' => true, 'customer_id' => 'CID1']);

        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);

        $this->clientMock->expects($this->once())
            ->method('validateTicketCreate')
            ->willReturn(['valid' => true]);

        $this->clientMock->expects($this->once())
            ->method('createTicket')
            ->willReturn(['success' => true, 'ticket_number' => 'TN123']);

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::UNCERTAIN, $result['outcome']);
        $this->assertEquals(ZnunyTicketCreationClassification::Uncertain->value, $result['classification']);
        $attempt = ZnunyTicketCreationAttempt::first();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Uncertain, $attempt->status);
    }

    private function createAttempt(string|int $runId, ZnunyTicketCreationAttemptStatus $status, ?int $ticketId = null, ?string $ticketNumber = null, string $sourceType = 'scheduled_run', ?string $createdAt = null): ZnunyTicketCreationAttempt
    {
        $data = [
            'source_type' => $sourceType,
            'source_id' => (string) $runId,
            'marker' => 'test-marker',
            'subject_original' => 'Subject',
            'subject_sent' => 'Subject',
            'status' => $status,
            'ticket_id' => $ticketId,
            'ticket_number' => $ticketNumber,
        ];
        if ($createdAt) {
            $data['created_at'] = $createdAt;
            $data['updated_at'] = $createdAt;
        }

        return ZnunyTicketCreationAttempt::create($data);
    }

    public function test_proceed_creates_exactly_one_new_attempt_and_calls_api_and_returns_fields()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test', 'enabled' => true, 'queue_name' => 'Q1',
            'owner_id' => 1, 'customer_user_login' => 'user1',
            'subject' => 'Sub', 'body' => 'Body',
        ]);

        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);
        $this->clientMock->expects($this->once())->method('createTicket')->willReturn(['success' => true, 'ticket_id' => 42, 'ticket_number' => 'TN42']);

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(1, ZnunyTicketCreationAttempt::count());
        $attempt = ZnunyTicketCreationAttempt::first();
        $this->assertEquals($attempt->id, $result['attempt_id']);
        $this->assertFalse($result['duplicate']);
        $this->assertFalse($result['recovered']);
    }

    public function test_prior_success_returns_success_without_api_call_and_returns_fields()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test', 'enabled' => true, 'queue_name' => 'Q1',
            'owner_id' => 1, 'customer_user_login' => 'user1',
            'subject' => 'Sub', 'body' => 'Body',
        ]);

        $attempt = $this->createAttempt(999, ZnunyTicketCreationAttemptStatus::Success, 42, 'TN42');

        $this->clientMock->expects($this->never())->method('createTicket');
        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::SUCCESS, $result['outcome']);
        $this->assertTrue($result['duplicate']);
        $this->assertFalse($result['recovered']);
        $this->assertEquals($attempt->id, $result['attempt_id']);
        $this->assertEquals(42, $result['ticket_id']);
        $this->assertEquals('TN42', $result['ticket_number']);
        $this->assertEquals(1, ZnunyTicketCreationAttempt::count());
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Success, $attempt->fresh()->status);
    }

    public function test_prior_recovered_is_reused_without_mutation()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test', 'enabled' => true, 'queue_name' => 'Q1',
            'owner_id' => 1, 'customer_user_login' => 'user1',
            'subject' => 'Sub', 'body' => 'Body',
        ]);

        $attempt = $this->createAttempt(999, ZnunyTicketCreationAttemptStatus::Recovered, 42, 'TN42');

        $this->clientMock->expects($this->never())->method('createTicket');
        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::SUCCESS, $result['outcome']);
        $this->assertTrue($result['duplicate']);
        $this->assertFalse($result['recovered']);
        $this->assertEquals($attempt->id, $result['attempt_id']);
        $this->assertEquals(42, $result['ticket_id']);
        $this->assertEquals('TN42', $result['ticket_number']);
        $this->assertEquals(1, ZnunyTicketCreationAttempt::count());
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Recovered, $attempt->fresh()->status);
    }

    public function test_prior_manually_linked_is_reused_without_mutation()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test', 'enabled' => true, 'queue_name' => 'Q1',
            'owner_id' => 1, 'customer_user_login' => 'user1',
            'subject' => 'Sub', 'body' => 'Body',
        ]);

        $attempt = $this->createAttempt(999, ZnunyTicketCreationAttemptStatus::ManuallyLinked, 42, 'TN42');

        $this->clientMock->expects($this->never())->method('createTicket');
        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::SUCCESS, $result['outcome']);
        $this->assertTrue($result['duplicate']);
        $this->assertFalse($result['recovered']);
        $this->assertEquals($attempt->id, $result['attempt_id']);
        $this->assertEquals(42, $result['ticket_id']);
        $this->assertEquals('TN42', $result['ticket_number']);
        $this->assertEquals(1, ZnunyTicketCreationAttempt::count());
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::ManuallyLinked, $attempt->fresh()->status);
    }

    public function test_prior_orphaned_becomes_recovered_without_api_call_and_returns_fields()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test', 'enabled' => true, 'queue_name' => 'Q1',
            'owner_id' => 1, 'customer_user_login' => 'user1',
            'subject' => 'Sub', 'body' => 'Body',
        ]);

        $attempt = $this->createAttempt(999, ZnunyTicketCreationAttemptStatus::Orphaned, 42, 'TN42');

        $this->clientMock->expects($this->never())->method('createTicket');
        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        $result = $this->service->createTicketFromTask($task, 999);

        $freshAttempt = $attempt->fresh();

        $this->assertEquals(ScheduledTicketCreationOutcome::SUCCESS, $result['outcome']);
        $this->assertTrue($result['duplicate']);
        $this->assertTrue($result['recovered']);
        $this->assertEquals($freshAttempt->id, $result['attempt_id']);
        $this->assertEquals($freshAttempt->ticket_id, $result['ticket_id']);
        $this->assertEquals($freshAttempt->ticket_number, $result['ticket_number']);
        $this->assertEquals(1, ZnunyTicketCreationAttempt::count());
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Recovered, $freshAttempt->status);
    }

    public function test_prior_sending_returns_uncertain_without_api_call()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test', 'enabled' => true, 'queue_name' => 'Q1',
            'owner_id' => 1, 'customer_user_login' => 'user1',
            'subject' => 'Sub', 'body' => 'Body',
        ]);

        $attempt = $this->createAttempt(999, ZnunyTicketCreationAttemptStatus::Sending, 99, 'TN99');

        $this->clientMock->expects($this->never())->method('createTicket');
        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::UNCERTAIN, $result['outcome']);
        $this->assertTrue($result['duplicate']);
        $this->assertFalse($result['recovered']);
        $this->assertEquals($attempt->id, $result['attempt_id']);
        $this->assertEquals(99, $result['ticket_id']);
        $this->assertEquals('TN99', $result['ticket_number']);
        $this->assertStringContainsString('Automatic duplicate creation was blocked', $result['error_summary']);
    }

    public function test_prior_uncertain_returns_uncertain_without_api_call()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test', 'enabled' => true, 'queue_name' => 'Q1',
            'owner_id' => 1, 'customer_user_login' => 'user1',
            'subject' => 'Sub', 'body' => 'Body',
        ]);

        $attempt = $this->createAttempt(999, ZnunyTicketCreationAttemptStatus::Uncertain);

        $this->clientMock->expects($this->never())->method('createTicket');
        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::UNCERTAIN, $result['outcome']);
        $this->assertTrue($result['duplicate']);
        $this->assertEquals($attempt->id, $result['attempt_id']);
    }

    public function test_invalid_confirmed_identifiers_return_uncertain_without_api_call()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test', 'enabled' => true, 'queue_name' => 'Q1',
            'owner_id' => 1, 'customer_user_login' => 'user1',
            'subject' => 'Sub', 'body' => 'Body',
        ]);

        $attempt = $this->createAttempt(999, ZnunyTicketCreationAttemptStatus::Success, null, 'TN42');

        $this->clientMock->expects($this->never())->method('createTicket');
        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::UNCERTAIN, $result['outcome']);
        $this->assertTrue($result['duplicate']);
        $this->assertFalse($result['recovered']);
        $this->assertEquals($attempt->id, $result['attempt_id']);
        $this->assertNull($result['ticket_id']);
        $this->assertEquals('TN42', $result['ticket_number']);
    }

    public function test_confirmed_failed_permits_a_new_attempt()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test', 'enabled' => true, 'queue_name' => 'Q1',
            'owner_id' => 1, 'customer_user_login' => 'user1',
            'subject' => 'Sub', 'body' => 'Body',
        ]);

        $this->createAttempt(999, ZnunyTicketCreationAttemptStatus::ConfirmedFailed);

        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        $this->clientMock->expects($this->once())->method('createTicket')->willReturn(['success' => true, 'ticket_id' => 43, 'ticket_number' => 'TN43']);

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::SUCCESS, $result['outcome']);
        $this->assertFalse($result['duplicate']);
        $this->assertEquals(2, ZnunyTicketCreationAttempt::count());
    }

    public function test_an_attempt_for_another_run_does_not_block_creation()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test', 'enabled' => true, 'queue_name' => 'Q1',
            'owner_id' => 1, 'customer_user_login' => 'user1',
            'subject' => 'Sub', 'body' => 'Body',
        ]);

        $this->createAttempt(888, ZnunyTicketCreationAttemptStatus::Sending);

        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        $this->clientMock->expects($this->once())->method('createTicket')->willReturn(['success' => true, 'ticket_id' => 43, 'ticket_number' => 'TN43']);

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::SUCCESS, $result['outcome']);
        $this->assertFalse($result['duplicate']);
        $this->assertEquals(2, ZnunyTicketCreationAttempt::count());
    }

    public function test_create_ticket_throws_reconciles_and_recovers_if_found()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test', 'enabled' => true, 'queue_name' => 'Q1',
            'owner_id' => 1, 'customer_user_login' => 'user1',
            'subject' => 'Sub', 'body' => 'Body',
        ]);

        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        $this->clientMock->expects($this->once())
            ->method('createTicket')
            ->willThrowException(new Exception('Network Timeout'));

        $cacheReaderMock = $this->createMock(\App\Services\Znuny\ZnunyTicketWorkspaceCacheReader::class);
        $cacheReaderMock->expects($this->once())
            ->method('getTickets')
            ->willReturnCallback(function () {
                $attempt = ZnunyTicketCreationAttempt::first();
                return [
                    [
                        'TicketID' => 88,
                        'TicketNumber' => 'TN88',
                        'Title' => 'Notification [' . $attempt->marker . ']',
                        'StateType' => 'open',
                    ]
                ];
            });

        $this->service = $this->buildService($cacheReaderMock);

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::SUCCESS, $result['outcome']);
        $this->assertTrue($result['recovered']);
        $this->assertFalse($result['duplicate']);
        $this->assertEquals(88, $result['ticket_id']);
        $this->assertEquals('TN88', $result['ticket_number']);

        $attempt = ZnunyTicketCreationAttempt::first();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Recovered, $attempt->status);
    }

    public function test_create_ticket_ambiguous_reconciles_remains_uncertain_if_not_found()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test', 'enabled' => true, 'queue_name' => 'Q1',
            'owner_id' => 1, 'customer_user_login' => 'user1',
            'subject' => 'Sub', 'body' => 'Body',
        ]);

        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        $this->clientMock->expects($this->once())
            ->method('createTicket')
            ->willReturn(['success' => false]);

        $cacheReaderMock = $this->createMock(\App\Services\Znuny\ZnunyTicketWorkspaceCacheReader::class);
        $cacheReaderMock->expects($this->exactly(2))
            ->method('getTickets')
            ->willReturnOnConsecutiveCalls([], []);

        $consoleMock = $this->createMock(\Illuminate\Contracts\Console\Kernel::class);
        $consoleMock->expects($this->once())
            ->method('call')
            ->with('znuny:warm-ticket-workspace-cache', ['--manual' => true])
            ->willReturn(0);

        $this->service = $this->buildService($cacheReaderMock, $consoleMock);

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::UNCERTAIN, $result['outcome']);
        $this->assertStringContainsString('No open Znuny ticket was found for the scheduled marker after refresh', $result['error_summary']);

        $attempt = ZnunyTicketCreationAttempt::first();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Uncertain, $attempt->status);
        $this->assertEquals(1, $attempt->check_attempts);
        $this->assertNotNull($attempt->last_checked_at);
    }

    public function test_create_ticket_throws_refresh_fails_remains_uncertain()
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test', 'enabled' => true, 'queue_name' => 'Q1',
            'owner_id' => 1, 'customer_user_login' => 'user1',
            'subject' => 'Sub', 'body' => 'Body',
        ]);

        $this->clientMock->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID1']);
        $this->defaultsMock->expects($this->once())->method('getDefaults')->willReturn(['state' => 'new', 'priority' => '3 normal', 'lock' => 'lock']);
        $this->clientMock->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);

        $this->clientMock->expects($this->once())
            ->method('createTicket')
            ->willThrowException(new Exception('Network Timeout'));

        $cacheReaderMock = $this->createMock(\App\Services\Znuny\ZnunyTicketWorkspaceCacheReader::class);
        $cacheReaderMock->expects($this->once())
            ->method('getTickets')
            ->willReturn([]);

        $consoleMock = $this->createMock(\Illuminate\Contracts\Console\Kernel::class);
        $consoleMock->expects($this->once())
            ->method('call')
            ->with('znuny:warm-ticket-workspace-cache', ['--manual' => true])
            ->willReturn(1);

        $this->service = $this->buildService($cacheReaderMock, $consoleMock);

        $result = $this->service->createTicketFromTask($task, 999);

        $this->assertEquals(ScheduledTicketCreationOutcome::UNCERTAIN, $result['outcome']);
        $this->assertStringContainsString('Failed to refresh the active Znuny Ticket Workspace cache.', $result['error_summary']);

        $attempt = ZnunyTicketCreationAttempt::first();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Uncertain, $attempt->status);
        $this->assertEquals(1, $attempt->check_attempts);
        $this->assertNotNull($attempt->last_checked_at);
    }
}
