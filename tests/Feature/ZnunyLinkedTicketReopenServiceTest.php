<?php

namespace Tests\Feature;

use App\Models\ZabbixTicket;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyLinkedTicketReopenService;
use App\Services\Znuny\ZnunyManualTicketLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZnunyLinkedTicketReopenServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_reopen_ticket_fails_if_not_candidate()
    {
        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => '1001',
            'zabbix_host_name' => 'Host',
            'zabbix_problem_name' => 'Problem',
            'znuny_ticket_id' => 12345,
            'znuny_ticket_number' => '123456789',
            'manual_lifecycle_status' => 'active',
        ]);

        $service = app(ZnunyLinkedTicketReopenService::class);
        $result = $service->reopenTicket($ticket, 'Testing reopen');

        $this->assertFalse($result['success']);
        $this->assertEquals('Ticket is not a valid manual reopen candidate.', $result['reason']);
    }

    public function test_reopen_ticket_sends_post_and_updates_status()
    {
        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => '1002',
            'zabbix_host_name' => 'Host',
            'zabbix_problem_name' => 'Problem',
            'manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_REOPEN_CANDIDATE,
            'znuny_ticket_id' => 12345,
            'znuny_ticket_number' => '123456789',
            'manual_close_eligible_at' => now()->subDay(),
            'zabbix_problem_is_active' => false,
        ]);

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldReceive('reopenTicket')
            ->once()
            ->with(12345, \Mockery::on(function ($payload) {
                return $payload['TicketID'] === 12345
                    && $payload['Reason'] === 'Operator note'
                    && $payload['Kind'] === 'internal_note'
                    && $payload['Subject'] === 'Manual reopen from Zabbix integration'
                    && $payload['Body'] === 'Operator note';
            }))
            ->andReturn([
                'success' => true,
                'raw' => [
                    'Success' => 1,
                    'State' => 'open',
                    'StateType' => 'open',
                ],
            ]);

        $service = app(ZnunyLinkedTicketReopenService::class);
        $result = $service->reopenTicket($ticket, 'Operator note');

        $this->assertTrue($result['success']);

        $ticket->refresh();
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_ACTIVE, $ticket->manual_lifecycle_status);
        $this->assertTrue($ticket->zabbix_problem_is_active);
        $this->assertNull($ticket->manual_close_eligible_at);
        $this->assertEquals('open', $ticket->znuny_state_name);
        $this->assertEquals('open', $ticket->znuny_ticket_state_type);
    }

    public function test_reopen_ticket_handles_failure_response()
    {
        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => '1003',
            'zabbix_host_name' => 'Host',
            'zabbix_problem_name' => 'Problem',
            'manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_REOPEN_CANDIDATE,
            'znuny_ticket_id' => 12345,
            'znuny_ticket_number' => '123456789',
        ]);

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldReceive('reopenTicket')
            ->once()
            ->andReturn([
                'success' => false,
                'errors' => ['API Error'],
                'warnings' => [],
                'raw' => [],
            ]);

        $service = app(ZnunyLinkedTicketReopenService::class);
        $result = $service->reopenTicket($ticket, 'Operator note');

        $this->assertFalse($result['success']);
        $this->assertEquals('API Error', $result['reason']);

        $ticket->refresh();
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_REOPEN_CANDIDATE, $ticket->manual_lifecycle_status);
    }
}
