<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\ZabbixTicketLinkService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketCreationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ZnunyTicketCreationServiceTest extends TestCase
{
    public function test_validate_ticket_payload_success()
    {
        $clientMock = $this->mock(ZnunyClient::class);

        $clientMock->shouldReceive('validateTicketCreate')
            ->once()
            ->with([
                'Ticket' => [
                    'Title' => 'Test Title',
                    'Queue' => 'TestQueue',
                    'CustomerUser' => 'testuser',
                    'State' => 'new',
                    'Lock' => 'lock',
                    'OwnerID' => 10,
                ],
                'Article' => [
                    'Subject' => 'Test Subject',
                    'Body' => 'Test Body',
                    'ContentType' => 'text/plain; charset=utf8',
                ],
            ])
            ->andReturn([
                'valid' => 1,
                'errors' => [],
                'warnings' => ['A warning'],
            ]);

        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);
        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock);

        $result = $service->validateTicketPayload(10, 'TestQueue', 'testuser', 'Test Title', 'Test Subject', 'Test Body');

        $this->assertTrue($result['valid']);
        $this->assertEquals([], $result['errors']);
        $this->assertEquals(['A warning'], $result['warnings']);
    }

    public function test_validate_ticket_payload_failure()
    {
        $clientMock = $this->mock(ZnunyClient::class);

        $clientMock->shouldReceive('validateTicketCreate')
            ->once()
            ->andReturn([
                'valid' => 0,
                'errors' => ['Missing data'],
                'warnings' => [],
            ]);

        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);
        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock);

        $result = $service->validateTicketPayload('10', 'TestQueue', 'testuser', 'Title', 'Subj', 'Body');

        $this->assertFalse($result['valid']);
        $this->assertEquals(['Missing data'], $result['errors']);
        $this->assertEquals([], $result['warnings']);
    }

    public function test_validate_ticket_payload_exception()
    {
        $clientMock = $this->mock(ZnunyClient::class);

        $clientMock->shouldReceive('validateTicketCreate')
            ->once()
            ->andThrow(new \Exception('API timeout'));

        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);
        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock);

        $result = $service->validateTicketPayload(10, 'TestQueue', 'testuser', 'Title', 'Subj', 'Body');

        $this->assertFalse($result['valid']);
        $this->assertEquals(['API timeout'], $result['errors']);
        $this->assertEquals([], $result['warnings']);
    }

    public function test_validate_ticket_payload_missing_title()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldNotReceive('validateTicketCreate');

        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);
        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock);

        $result = $service->validateTicketPayload(10, 'TestQueue', 'testuser', '', 'Subj', 'Body');

        $this->assertFalse($result['valid']);
        $this->assertEquals(['Ticket title is required.'], $result['errors']);
    }

    public function test_validate_ticket_payload_missing_body()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldNotReceive('validateTicketCreate');

        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);
        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock);

        $result = $service->validateTicketPayload(10, 'TestQueue', 'testuser', 'Title', 'Subj', '');

        $this->assertFalse($result['valid']);
        $this->assertEquals(['Ticket article body is required.'], $result['errors']);
    }

    public function test_create_ticket_missing_fields()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);
        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock);

        $result = $service->createTicketForProblem('', 'Host', 'Prob', 10, 'Q', 'CU', 'T', 'S', 'B');
        $this->assertFalse($result['success']);
        $this->assertContains('Missing required fields for ticket creation.', $result['errors']);
    }

    public function test_create_ticket_lock_unavailable()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);
        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock);

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
        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock);

        $linkServiceMock->shouldReceive('existsForEventId')->once()->with('123')->andReturn(true);
        $linkServiceMock->shouldReceive('findByEventId')->once()->with('123')->andReturn(
            new \App\Models\ZabbixTicket(['znuny_ticket_id' => 99, 'znuny_ticket_number' => 'TN99'])
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

        $linkServiceMock->shouldReceive('existsForEventId')->once()->with('123')->andReturn(false);
        $clientMock->shouldReceive('validateTicketCreate')->once()->andReturn([
            'valid' => 0, 'errors' => ['ValErr'],
        ]);

        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock);

        $result = $service->createTicketForProblem('123', 'Host', 'Prob', 10, 'Q', 'CU', 'T', 'S', 'B');

        $this->assertFalse($result['success']);
        $this->assertContains('ValErr', $result['errors']);
    }

    public function test_create_ticket_remote_failure()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);

        $linkServiceMock->shouldReceive('existsForEventId')->once()->with('123')->andReturn(false);
        $clientMock->shouldReceive('validateTicketCreate')->once()->andReturn(['valid' => 1]);
        $clientMock->shouldReceive('createTicket')->once()->andReturn([
            'success' => false, 'errors' => ['ApiErr'],
        ]);

        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock);

        $result = $service->createTicketForProblem('123', 'Host', 'Prob', 10, 'Q', 'CU', 'T', 'S', 'B');

        $this->assertFalse($result['success']);
        $this->assertContains('ApiErr', $result['errors']);
    }

    public function test_create_ticket_success()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);

        $linkServiceMock->shouldReceive('existsForEventId')->once()->with('123')->andReturn(false);
        $clientMock->shouldReceive('validateTicketCreate')->once()->andReturn(['valid' => 1]);
        $clientMock->shouldReceive('createTicket')->once()->andReturn([
            'success' => true, 'ticket_id' => 55, 'ticket_number' => 'TN55',
        ]);
        $linkServiceMock->shouldReceive('create')->once()->with(\Mockery::on(function ($arg) {
            return $arg['zabbix_event_id'] === '123' && $arg['znuny_ticket_id'] === 55;
        }));

        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock);

        $result = $service->createTicketForProblem('123', 'Host', 'Prob', 10, 'Q', 'CU', 'T', 'S', 'B');

        $this->assertTrue($result['success']);
        $this->assertEquals(55, $result['ticket_id']);
        $this->assertEquals('TN55', $result['ticket_number']);
    }

    public function test_create_ticket_missing_ticket_number()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);

        $linkServiceMock->shouldReceive('existsForEventId')->once()->with('123')->andReturn(false);
        $clientMock->shouldReceive('validateTicketCreate')->once()->andReturn(['valid' => 1]);
        $clientMock->shouldReceive('createTicket')->once()->andReturn([
            'success' => true, 'ticket_id' => 55, 'ticket_number' => null,
        ]);

        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock);

        $result = $service->createTicketForProblem('123', 'Host', 'Prob', 10, 'Q', 'CU', 'T', 'S', 'B');

        $this->assertFalse($result['success']);
        $this->assertContains('Ticket created but missing TicketID or TicketNumber in response.', $result['errors']);
    }

    public function test_create_ticket_orphaned()
    {
        $clientMock = $this->mock(ZnunyClient::class);
        $linkServiceMock = $this->mock(ZabbixTicketLinkService::class);

        $linkServiceMock->shouldReceive('existsForEventId')->once()->with('123')->andReturn(false);
        $clientMock->shouldReceive('validateTicketCreate')->once()->andReturn(['valid' => 1]);
        $clientMock->shouldReceive('createTicket')->once()->andReturn([
            'success' => true, 'ticket_id' => 55, 'ticket_number' => 'TN55',
        ]);
        $linkServiceMock->shouldReceive('create')->once()->andThrow(new \Exception('DB failure'));

        Log::shouldReceive('critical')->once();

        $service = new ZnunyTicketCreationService($clientMock, $linkServiceMock);

        $result = $service->createTicketForProblem('123', 'Host', 'Prob', 10, 'Q', 'CU', 'T', 'S', 'B');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['orphaned']);
        $this->assertEquals(55, $result['ticket_id']);
        $this->assertEquals('TN55', $result['ticket_number']);
        $this->assertContains('Znuny ticket was created but linking to Zabbix problem failed locally.', $result['errors']);
    }
}
