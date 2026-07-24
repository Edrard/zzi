<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\ZabbixTicket;
use App\Services\OwnerSuggestion\OwnerSuggestionObservationRecorder;
use App\Services\Znuny\ZabbixTicketLinkService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketAdvancedDefaultsService;
use App\Services\Znuny\ZnunyTicketCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Tests\TestCase;

class ZnunyTicketCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_ticket_payload_success()
    {
        $clientMock = $this->mock(ZnunyClient::class);

        $this->mock(ZnunyTicketAdvancedDefaultsService::class, function ($mock) {
            $mock->shouldReceive('getDefaults')->andReturn([
                'priority' => '4 high',
                'state' => 'open',
                'lock' => 'unlock',
            ]);
        });

        $clientMock->shouldReceive('validateTicketCreate')
            ->once()
            ->with([
                'OwnerID' => 10,
                'Queue' => 'TestQueue',
                'CustomerUser' => 'testuser',
                'CustomerID' => 'CUST123',
                'State' => 'open',
                'Lock' => 'unlock',
                'Priority' => '4 high',
            ])
            ->andReturn([
                'valid' => 1,
                'errors' => [],
                'warnings' => ['A warning'],
            ]);

        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);
        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock, $this->mock(OwnerSuggestionObservationRecorder::class));

        $result = $service->validateTicketPayload(10, 'TestQueue', 'testuser', 'CUST123', 'Test Title', 'Test Subject', 'Test Body');

        $this->assertTrue($result['valid']);
        $this->assertEquals([], $result['errors']);
        $this->assertEquals(['A warning'], $result['warnings']);
    }

    public function test_validate_ticket_payload_flat_regression()
    {
        $clientMock = $this->mock(ZnunyClient::class);

        $this->mock(ZnunyTicketAdvancedDefaultsService::class, function ($mock) {
            $mock->shouldReceive('getDefaults')->andReturn([
                'priority' => '4 high',
                'state' => 'open',
                'lock' => 'unlock',
            ]);
        });

        $clientMock->shouldReceive('validateTicketCreate')
            ->once()
            ->with([
                'OwnerID' => 2,
                'Queue' => 'Rental',
                'CustomerUser' => 'RentalClients',
                'CustomerID' => 'CUST_RENTAL',
                'State' => 'open',
                'Lock' => 'unlock',
                'Priority' => '4 high',
            ])
            ->andReturn([
                'valid' => 1,
                'errors' => [],
                'warnings' => [],
            ]);

        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);
        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock, $this->mock(OwnerSuggestionObservationRecorder::class));

        $result = $service->validateTicketPayload(2, 'Rental', 'RentalClients', 'CUST_RENTAL', 'Title', 'Subject', 'Body');

        $this->assertTrue($result['valid']);
        $this->assertEquals([], $result['errors']);
    }

    public function test_validate_ticket_payload_failure()
    {
        $clientMock = $this->mock(ZnunyClient::class);

        $this->mock(ZnunyTicketAdvancedDefaultsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getDefaults')->andReturn([
                'priority' => '3 normal',
                'state' => 'new',
                'lock' => 'lock',
            ]);
        });

        $clientMock->shouldReceive('validateTicketCreate')
            ->once()
            ->andReturn([
                'valid' => 0,
                'errors' => ['Missing data'],
                'warnings' => [],
            ]);

        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);
        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock, $this->mock(OwnerSuggestionObservationRecorder::class));

        $result = $service->validateTicketPayload('10', 'TestQueue', 'testuser', 'CUST123', 'Title', 'Subj', 'Body');

        $this->assertFalse($result['valid']);
        $this->assertEquals(['Missing data'], $result['errors']);
        $this->assertEquals([], $result['warnings']);
    }

    public function test_validate_ticket_payload_exception()
    {
        $clientMock = $this->mock(ZnunyClient::class);

        $this->mock(ZnunyTicketAdvancedDefaultsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getDefaults')->andReturn([
                'priority' => '3 normal',
                'state' => 'new',
                'lock' => 'lock',
            ]);
        });

        $clientMock->shouldReceive('validateTicketCreate')
            ->once()
            ->andThrow(new \Exception('API timeout'));

        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);
        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock, $this->mock(OwnerSuggestionObservationRecorder::class));

        $result = $service->validateTicketPayload(10, 'TestQueue', 'testuser', 'CUST123', 'Title', 'Subj', 'Body');

        $this->assertFalse($result['valid']);
        $this->assertEquals(['API timeout'], $result['errors']);
        $this->assertEquals([], $result['warnings']);
    }

    public function test_validate_ticket_payload_missing_title()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldNotReceive('validateTicketCreate');

        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);
        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock, $this->mock(OwnerSuggestionObservationRecorder::class));

        $result = $service->validateTicketPayload(10, 'TestQueue', 'testuser', 'CUST123', '', 'Subj', 'Body');

        $this->assertFalse($result['valid']);
        $this->assertEquals(['Ticket title is required.'], $result['errors']);
    }

    public function test_validate_ticket_payload_missing_body()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldNotReceive('validateTicketCreate');

        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);
        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock, $this->mock(OwnerSuggestionObservationRecorder::class));

        $result = $service->validateTicketPayload(10, 'TestQueue', 'testuser', 'CUST123', 'Title', 'Subj', '');

        $this->assertFalse($result['valid']);
        $this->assertEquals(['Ticket article body is required.'], $result['errors']);
    }

    public function test_create_ticket_missing_fields()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);
        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock, $this->mock(OwnerSuggestionObservationRecorder::class));

        $result = $service->createTicketForProblem('', '', '', 10, 'Q', 'CU', 'T', 'S', 'B');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Missing required fields for ticket creation:', $result['errors'][0]);
        $this->assertStringContainsString('event ID', $result['errors'][0]);
    }

    public function test_create_ticket_customer_user_not_found()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);

        $linkServiceMock->shouldReceive('existsForEventId')->once()->with('123')->andReturn(false);
        $clientMock->shouldReceive('getCustomerUser')->once()->with('CU')->andReturn(['found' => false]);
        $clientMock->shouldNotReceive('validateTicketCreate');
        $clientMock->shouldNotReceive('createTicket');

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock, $this->mock(OwnerSuggestionObservationRecorder::class));

        $result = $service->createTicketForProblem('123', 'Host', 'Prob', 10, 'Q', 'CU', 'T', 'S', 'B');
        $this->assertFalse($result['success']);
        $this->assertContains('Failed to resolve CustomerUser: CU', $result['errors']);
    }

    public function test_create_ticket_customer_id_missing()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);

        $linkServiceMock->shouldReceive('existsForEventId')->once()->with('123')->andReturn(false);
        $clientMock->shouldReceive('getCustomerUser')->once()->with('CU')->andReturn(['found' => true, 'customer_id' => '  ']);
        $clientMock->shouldNotReceive('validateTicketCreate');
        $clientMock->shouldNotReceive('createTicket');

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock, $this->mock(OwnerSuggestionObservationRecorder::class));

        $result = $service->createTicketForProblem('123', 'Host', 'Prob', 10, 'Q', 'CU', 'T', 'S', 'B');
        $this->assertFalse($result['success']);
        $this->assertContains("CustomerUser 'CU' has no CustomerID/UserCustomerID assigned.", $result['errors']);
    }

    public function test_create_ticket_lock_unavailable()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);
        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock, $this->mock(OwnerSuggestionObservationRecorder::class));

        $lock = Cache::lock('zbx_ticket_create:123', 10);
        $lock->get(); // Acquire lock so the service cannot

        $result = $service->createTicketForProblem('123', 'Host', 'Prob', 10, 'Q', 'CU', 'T', 'S', 'B');
        $this->assertFalse($result['success']);
        $this->assertTrue($result['locked']);

        $lock->release();
    }

    public function test_create_ticket_duplicate_link()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);

        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock, $this->mock(OwnerSuggestionObservationRecorder::class));

        $linkServiceMock->shouldReceive('existsForEventId')->once()->with('123')->andReturn(true);
        $linkServiceMock->shouldReceive('findByEventId')->once()->with('123')->andReturn(
            new ZabbixTicket(['znuny_ticket_id' => 99, 'znuny_ticket_number' => 'TN99'])
        );

        $result = $service->createTicketForProblem('123', 'Host', 'Prob', 10, 'Q', 'CU', 'T', 'S', 'B');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['duplicate']);
        $this->assertEquals(99, $result['ticket_id']);
        $this->assertEquals('TN99', $result['ticket_number']);
    }

    public function test_create_ticket_validation_failure()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);

        $this->mock(ZnunyTicketAdvancedDefaultsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getDefaults')->andReturn([
                'priority' => '3 normal',
                'state' => 'new',
                'lock' => 'lock',
            ]);
        });

        $clientMock->shouldReceive('getCustomerUser')->once()->with('CU')->andReturn(['found' => true, 'customer_id' => 'CUST_123']);
        $linkServiceMock->shouldReceive('existsForEventId')->once()->with('123')->andReturn(false);
        $clientMock->shouldReceive('validateTicketCreate')->once()->andReturn([
            'valid' => 0, 'errors' => ['ValErr'],
        ]);

        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock, $this->mock(OwnerSuggestionObservationRecorder::class));

        $result = $service->createTicketForProblem('123', 'Host', 'Prob', 10, 'Q', 'CU', 'T', 'S', 'B');

        $this->assertFalse($result['success']);
        $this->assertContains('ValErr', $result['errors']);
    }

    public function test_create_ticket_remote_failure()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);

        $this->mock(ZnunyTicketAdvancedDefaultsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getDefaults')->andReturn([
                'priority' => '3 normal',
                'state' => 'new',
                'lock' => 'lock',
            ]);
        });

        $clientMock->shouldReceive('getCustomerUser')->once()->with('CU')->andReturn(['found' => true, 'customer_id' => 'CUST_123']);
        $linkServiceMock->shouldReceive('existsForEventId')->once()->with('123')->andReturn(false);
        $clientMock->shouldReceive('validateTicketCreate')->once()->andReturn(['valid' => 1]);
        $clientMock->shouldReceive('createTicket')->once()->andReturn([
            'success' => false, 'errors' => ['ApiErr'],
        ]);

        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock, $this->mock(OwnerSuggestionObservationRecorder::class));

        $result = $service->createTicketForProblem('123', 'Host', 'Prob', 10, 'Q', 'CU', 'T', 'S', 'B');

        $this->assertFalse($result['success']);
        $this->assertContains('ApiErr', $result['errors']);
    }

    public function test_create_ticket_success()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);

        $this->mock(ZnunyTicketAdvancedDefaultsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getDefaults')->andReturn([
                'priority' => '3 normal',
                'state' => 'new',
                'lock' => 'lock',
            ]);
        });

        $clientMock->shouldReceive('getCustomerUser')->once()->with('CU')->andReturn(['found' => true, 'customer_id' => 'CUST_123']);
        $linkServiceMock->shouldReceive('existsForEventId')->once()->with('123')->andReturn(false);
        $clientMock->shouldReceive('validateTicketCreate')->once()->andReturn(['valid' => 1]);

        $clientMock->shouldReceive('createTicket')->once()->with(\Mockery::on(function ($payload) {
            return $payload['Ticket']['CustomerUser'] === 'CU'
                && $payload['Ticket']['CustomerID'] === 'CUST_123'
                && $payload['Ticket']['OwnerID'] === 10
                && $payload['Ticket']['Queue'] === 'Q'
                && $payload['Ticket']['Title'] === 'T [ZBX:123]'
                && $payload['Article']['Subject'] === 'T [ZBX:123]'
                && $payload['Article']['Body'] === 'B'
                && $payload['Article']['IsVisibleForCustomer'] === 1;
        }))->andReturn([
            'success' => true, 'ticket_id' => 55, 'ticket_number' => 'TN55',
        ]);

        $linkServiceMock->shouldReceive('create')->once()->with(\Mockery::on(function ($arg) {
            return $arg['zabbix_event_id'] === '123' && $arg['znuny_ticket_id'] === 55;
        }));

        $observationRecorderMock = $this->mock(OwnerSuggestionObservationRecorder::class);
        $observationRecorderMock->shouldReceive('recordManualTicketCreated')
            ->once()
            ->with(\Mockery::on(function ($data) {
                return $data['problem_name'] === 'Prob'
                    && $data['queue_name'] === 'Q'
                    && $data['owner_id'] === 10
                    && $data['zabbix_event_id'] === '123'
                    && $data['zabbix_host_name'] === 'Host'
                    && $data['customer_user_login'] === 'CU'
                    && $data['znuny_ticket_id'] === 55;
            }));

        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock, $observationRecorderMock);

        $result = $service->createTicketForProblem('123', 'Host', 'Prob', 10, 'Q', 'CU', 'T', 'S', 'B');
        $this->assertTrue($result['success']);
        $this->assertEquals(55, $result['ticket_id']);
        $this->assertEquals('TN55', $result['ticket_number']);
    }

    public function test_create_ticket_success_even_if_observation_recorder_throws()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);

        $this->mock(ZnunyTicketAdvancedDefaultsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getDefaults')->andReturn([
                'priority' => '3 normal',
                'state' => 'new',
                'lock' => 'lock',
            ]);
        });

        $clientMock->shouldReceive('getCustomerUser')->once()->with('CU')->andReturn(['found' => true, 'customer_id' => 'CUST_123']);
        $linkServiceMock->shouldReceive('existsForEventId')->once()->with('123')->andReturn(false);
        $clientMock->shouldReceive('validateTicketCreate')->once()->andReturn(['valid' => 1]);

        $clientMock->shouldReceive('createTicket')->once()->andReturn([
            'success' => true, 'ticket_id' => 55, 'ticket_number' => 'TN55',
        ]);

        $linkServiceMock->shouldReceive('create')->once();

        $observationRecorderMock = $this->mock(OwnerSuggestionObservationRecorder::class);
        $observationRecorderMock->shouldReceive('recordManualTicketCreated')
            ->once()
            ->andThrow(new \Exception('Recorder DB went away'));

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Failed to record manual ticket creation observation from ZnunyTicketCreationService'
                    && isset($context['error'])
                    && $context['error'] === 'Recorder DB went away'
                    && $context['zabbix_event_id'] === '123'
                    && $context['znuny_ticket_id'] === 55;
            });

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock, $observationRecorderMock);

        $result = $service->createTicketForProblem('123', 'Host', 'Prob', 10, 'Q', 'CU', 'T', 'S', 'B');

        $this->assertTrue($result['success']);
        $this->assertEquals(55, $result['ticket_id']);
        $this->assertEquals('TN55', $result['ticket_number']);
        $this->assertEmpty($result['errors']);
    }

    public function test_create_ticket_missing_ticket_number()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);

        $this->mock(ZnunyTicketAdvancedDefaultsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getDefaults')->andReturn([
                'priority' => '3 normal',
                'state' => 'new',
                'lock' => 'lock',
            ]);
        });

        $clientMock->shouldReceive('getCustomerUser')->once()->with('CU')->andReturn(['found' => true, 'customer_id' => 'CUST_123']);
        $linkServiceMock->shouldReceive('existsForEventId')->once()->with('123')->andReturn(false);
        $clientMock->shouldReceive('validateTicketCreate')->once()->andReturn(['valid' => 1]);
        $clientMock->shouldReceive('createTicket')->once()->andReturn([
            'success' => true, 'ticket_id' => 55, 'ticket_number' => null,
        ]);

        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock, $this->mock(OwnerSuggestionObservationRecorder::class));

        $result = $service->createTicketForProblem('123', 'Host', 'Prob', 10, 'Q', 'CU', 'T', 'S', 'B');

        $this->assertFalse($result['success']);
        $this->assertContains('Ticket created but missing TicketID or TicketNumber in response.', $result['errors']);
    }

    public function test_create_ticket_orphaned()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);

        $this->mock(ZnunyTicketAdvancedDefaultsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getDefaults')->andReturn([
                'priority' => '3 normal',
                'state' => 'new',
                'lock' => 'lock',
            ]);
        });

        $clientMock->shouldReceive('getCustomerUser')->once()->with('CU')->andReturn(['found' => true, 'customer_id' => 'CUST_123']);
        $linkServiceMock->shouldReceive('existsForEventId')->once()->with('123')->andReturn(false);
        $clientMock->shouldReceive('validateTicketCreate')->once()->andReturn(['valid' => 1]);
        $clientMock->shouldReceive('createTicket')->once()->andReturn([
            'success' => true, 'ticket_id' => 55, 'ticket_number' => 'TN55',
        ]);
        $linkServiceMock->shouldReceive('create')->once()->andThrow(new \Exception('DB failure'));

        Log::shouldReceive('critical')->once();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock, $this->mock(OwnerSuggestionObservationRecorder::class));

        $result = $service->createTicketForProblem('123', 'Host', 'Prob', 10, 'Q', 'CU', 'T', 'S', 'B');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['orphaned']);
        $this->assertEquals(55, $result['ticket_id']);
        $this->assertEquals('TN55', $result['ticket_number']);
        $this->assertContains('Znuny ticket was created but linking to Zabbix problem failed locally.', $result['errors']);
    }
}
