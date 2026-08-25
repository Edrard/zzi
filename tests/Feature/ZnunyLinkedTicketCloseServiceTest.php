<?php

namespace Tests\Feature;

use App\Models\ZabbixTicket;
use App\Services\Zabbix\ZabbixProblemCache;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyLinkedTicketCloseService;
use App\Services\Znuny\ZnunyManualTicketLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ZnunyLinkedTicketCloseServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ZabbixProblemCache::class)->clear();
        Redis::set('zabbix:problems:last_poll', json_encode([
            'status' => 'success',
            'polled_at' => now()->toIso8601String(),
            'last_successful_polled_at' => now()->toIso8601String(),
        ]));
    }

    public function test_successful_manual_close_updates_manual_lifecycle_closed_at()
    {
        Carbon::setTestNow(now());

        $ticket = ZabbixTicket::create([
            'znuny_ticket_id' => 10,
            'znuny_ticket_number' => '123456',
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'manual_lifecycle_status' => 'active',
            'manual_lifecycle_closed_at' => now()->subDays(5), // Old stale value
            'manual_close_eligible_at' => now()->subHours(1),
            'manual_flap_count' => 0,
            'zabbix_problem_is_active' => true,
        ]);

        $mockClient = $this->mock(ZnunyClient::class);
        $mockClient->shouldReceive('closeTicket')
            ->once()
            ->with(10, \Mockery::type('array'))
            ->andReturn([
                'success' => true,
                'state' => 'closed successful',
                'state_type' => 'closed',
            ]);
        $mockClient->shouldReceive('unlockTicket')
            ->once()
            ->with(10)
            ->andReturn(['success' => true]);

        $service = app(ZnunyLinkedTicketCloseService::class);
        $result = $service->closeTicket($ticket, 'Subject', 'Body', 'Reason');

        $this->assertTrue($result['success']);

        $ticket->refresh();

        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CLOSED, $ticket->manual_lifecycle_status);
        $this->assertEquals(now()->toDateTimeString(), $ticket->manual_lifecycle_closed_at->toDateTimeString());
        $this->assertNull($ticket->manual_close_eligible_at);
        $this->assertEquals('closed successful', $ticket->znuny_state_name);
        $this->assertEquals('closed', $ticket->znuny_ticket_state_type);
        $this->assertEquals(0, $ticket->manual_flap_count); // Flap count not incremented
    }

    public function test_successful_manual_close_with_active_problem_becomes_reopen_candidate()
    {
        Carbon::setTestNow(now());

        $ticket = ZabbixTicket::create([
            'znuny_ticket_id' => 10,
            'znuny_ticket_number' => '123456',
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'manual_lifecycle_status' => 'active',
            'manual_lifecycle_closed_at' => now()->subDays(5),
            'zabbix_problem_is_active' => true,
        ]);

        app(ZabbixProblemCache::class)->putMany([
            [
                'eventid' => 'evt2',
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
            ],
        ], 60);

        $mockClient = $this->mock(ZnunyClient::class);
        $mockClient->shouldReceive('closeTicket')
            ->once()
            ->with(10, \Mockery::type('array'))
            ->andReturn([
                'success' => true,
                'state' => 'closed successful',
                'state_type' => 'closed',
            ]);
        $mockClient->shouldReceive('unlockTicket')
            ->once()
            ->with(10)
            ->andReturn(['success' => true]);

        $service = app(ZnunyLinkedTicketCloseService::class);
        $result = $service->closeTicket($ticket, 'Subject', 'Body', 'Reason');

        $this->assertTrue($result['success']);
        $this->assertNull($result['warning']);

        $ticket->refresh();

        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_REOPEN_CANDIDATE, $ticket->manual_lifecycle_status);
        $this->assertEquals(now()->toDateTimeString(), $ticket->manual_lifecycle_closed_at->toDateTimeString());
        $this->assertTrue($ticket->zabbix_problem_is_active);
    }

    public function test_successful_manual_close_with_unlock_failure_returns_warning_but_succeeds()
    {
        Carbon::setTestNow(now());

        $ticket = ZabbixTicket::create([
            'znuny_ticket_id' => 10,
            'znuny_ticket_number' => '123456',
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'manual_lifecycle_status' => 'active',
            'zabbix_problem_is_active' => false,
        ]);

        $mockClient = $this->mock(ZnunyClient::class);
        $mockClient->shouldReceive('closeTicket')
            ->once()
            ->with(10, \Mockery::type('array'))
            ->andReturn([
                'success' => true,
                'state' => 'closed successful',
                'state_type' => 'closed',
            ]);
        $mockClient->shouldReceive('unlockTicket')
            ->once()
            ->with(10)
            ->andReturn([
                'success' => false,
                'errors' => ['Cannot unlock'],
            ]);

        $service = app(ZnunyLinkedTicketCloseService::class);
        $result = $service->closeTicket($ticket, 'Subject', 'Body', 'Reason');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Cannot unlock', $result['warning']);

        $ticket->refresh();
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CLOSED, $ticket->manual_lifecycle_status);
    }
}
