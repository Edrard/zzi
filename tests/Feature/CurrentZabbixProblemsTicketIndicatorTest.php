<?php

namespace Tests\Feature;

use App\Filament\Pages\CurrentZabbixProblems;
use App\Models\ZabbixTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentZabbixProblemsTicketIndicatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_resolver_direct_eventid_match_returns_normal_ticket()
    {
        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_severity' => 3,
            'zabbix_trigger_id' => 'trg1',
            'zabbix_problem_name' => 'Problem 1',
            'znuny_ticket_id' => 50001,
            'znuny_ticket_number' => '1001',
            'manual_lifecycle_status' => 'active',
        ]);

        $page = new CurrentZabbixProblems;
        $problems = [
            [
                'eventid' => 'evt1',
                'hosts' => [['hostid' => 'host1']],
                'objectid' => 'trg1',
            ],
        ];

        $resolved = $page->resolveLinkedTickets($problems);

        $this->assertArrayHasKey('evt1', $resolved);
        $this->assertEquals($ticket->id, $resolved['evt1']->id);

        $indicator = $page->getProblemTicketIndicator($resolved['evt1']);
        $this->assertEquals('linked', $indicator['kind']);
        $this->assertEquals('heroicon-o-ticket', $indicator['icon']);
    }

    public function test_resolver_geos_like_mismatch_returns_normal_ticket()
    {
        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt_old',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_severity' => 3,
            'zabbix_trigger_id' => 'trg1',
            'zabbix_problem_name' => 'Problem 1',
            'znuny_ticket_id' => 50001,
            'znuny_ticket_number' => '1001',
            'manual_lifecycle_status' => 'active',
            'znuny_ticket_state_type' => 'open',
        ]);

        $page = new CurrentZabbixProblems;
        $problems = [
            [
                'eventid' => 'evt_new',
                'hosts' => [['hostid' => 'host1']],
                'objectid' => 'trg1',
            ],
        ];

        $resolved = $page->resolveLinkedTickets($problems);

        $this->assertArrayHasKey('evt_new', $resolved);
        $this->assertEquals($ticket->id, $resolved['evt_new']->id);

        $indicator = $page->getProblemTicketIndicator($resolved['evt_new']);
        $this->assertEquals('linked', $indicator['kind']);
        $this->assertEquals('heroicon-o-ticket', $indicator['icon']);
    }

    public function test_resolver_reopen_candidate_returns_warning()
    {
        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt_old',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_severity' => 3,
            'zabbix_trigger_id' => 'trg1',
            'zabbix_problem_name' => 'Problem 1',
            'znuny_ticket_id' => 50001,
            'znuny_ticket_number' => '1001',
            'manual_lifecycle_status' => 'reopen_candidate',
        ]);

        $page = new CurrentZabbixProblems;
        $problems = [
            [
                'eventid' => 'evt_new',
                'hosts' => [['hostid' => 'host1']],
                'objectid' => 'trg1',
            ],
        ];

        $resolved = $page->resolveLinkedTickets($problems);

        $this->assertArrayHasKey('evt_new', $resolved);
        $this->assertEquals($ticket->id, $resolved['evt_new']->id);

        $indicator = $page->getProblemTicketIndicator($resolved['evt_new']);
        $this->assertEquals('reopen_candidate', $indicator['kind']);
        $this->assertEquals('heroicon-o-exclamation-triangle', $indicator['icon']);
    }

    public function test_resolver_reopened_returns_orange_ticket()
    {
        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt_old',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_severity' => 3,
            'zabbix_trigger_id' => 'trg1',
            'zabbix_problem_name' => 'Problem 1',
            'znuny_ticket_id' => 50001,
            'znuny_ticket_number' => '1001',
            'manual_lifecycle_status' => 'reopened',
        ]);

        $page = new CurrentZabbixProblems;
        $problems = [
            [
                'eventid' => 'evt_new',
                'hosts' => [['hostid' => 'host1']],
                'objectid' => 'trg1',
            ],
        ];

        $resolved = $page->resolveLinkedTickets($problems);

        $this->assertArrayHasKey('evt_new', $resolved);
        $this->assertEquals($ticket->id, $resolved['evt_new']->id);

        $indicator = $page->getProblemTicketIndicator($resolved['evt_new']);
        $this->assertEquals('reopened', $indicator['kind']);
        $this->assertEquals('heroicon-o-ticket', $indicator['icon']);
        $this->assertStringContainsString('text-orange-500', $indicator['class']);
    }

    public function test_resolver_flapping_returns_flapping_icon()
    {
        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt_old',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_severity' => 3,
            'zabbix_trigger_id' => 'trg1',
            'zabbix_problem_name' => 'Problem 1',
            'znuny_ticket_id' => 50001,
            'znuny_ticket_number' => '1001',
            'manual_lifecycle_status' => 'flapping',
        ]);

        $page = new CurrentZabbixProblems;
        $problems = [
            [
                'eventid' => 'evt_new',
                'hosts' => [['hostid' => 'host1']],
                'objectid' => 'trg1',
            ],
        ];

        $resolved = $page->resolveLinkedTickets($problems);

        $this->assertArrayHasKey('evt_new', $resolved);
        $this->assertEquals($ticket->id, $resolved['evt_new']->id);

        $indicator = $page->getProblemTicketIndicator($resolved['evt_new']);
        $this->assertEquals('flapping', $indicator['kind']);
        $this->assertEquals('heroicon-o-exclamation-triangle', $indicator['icon']);
        $this->assertStringContainsString('danger', $indicator['class']);
    }

    public function test_resolver_no_linked_ticket_returns_empty_indicator()
    {
        $page = new CurrentZabbixProblems;
        $problems = [
            [
                'eventid' => 'evt_new',
                'hosts' => [['hostid' => 'host1']],
                'objectid' => 'trg1',
            ],
        ];

        $resolved = $page->resolveLinkedTickets($problems);

        $this->assertArrayNotHasKey('evt_new', $resolved);

        $indicator = $page->getProblemTicketIndicator(null);
        $this->assertEmpty($indicator);
    }
}
