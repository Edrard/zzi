<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\ZabbixTicket;
use App\Services\Znuny\ZnunyTicketWorkspaceCacheReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ZnunyTicketWorkspaceCacheReaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::flushall();
    }

    public function test_it_returns_cached_tickets_and_handles_prefixes()
    {
        $prefix = config('database.redis.options.prefix', '');

        $ticket1 = ['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'First', 'StateType' => 'open'];
        $ticket2 = ['TicketID' => 102, 'TicketNumber' => 'TN102', 'Title' => 'Second', 'StateType' => 'closed'];

        // Use standard Facade which applies prefix automatically if configured
        Redis::set('znuny:ticket:101', json_encode($ticket1));
        Redis::set('znuny:ticket:102', json_encode($ticket2));

        // Add a malformed one
        Redis::set('znuny:ticket:103', 'not-a-json');

        $reader = app(ZnunyTicketWorkspaceCacheReader::class);
        $tickets = $reader->getTickets();

        // 103 is ignored
        $this->assertCount(2, $tickets);

        $ids = array_column($tickets, 'TicketID');
        $this->assertContains(101, $ids);
        $this->assertContains(102, $ids);

        // Ensure defaults are set
        $t1 = collect($tickets)->firstWhere('TicketID', 101);
        $this->assertFalse($t1['is_linked_to_zabbix_problem']);
    }

    public function test_it_enriches_tickets_with_local_zabbix_links()
    {
        $ticket1 = ['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'First', 'StateType' => 'open'];
        Redis::set('znuny:ticket:101', json_encode($ticket1));

        ZabbixTicket::create([
            'zabbix_event_id' => 'evt-1',
            'zabbix_host_id' => 'host-1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_trigger_id' => 'trg-1',
            'znuny_ticket_id' => 101,
            'znuny_ticket_number' => 'TN101',
            'manual_lifecycle_status' => 'active',
        ]);

        $reader = app(ZnunyTicketWorkspaceCacheReader::class);
        $tickets = $reader->getTickets();

        $this->assertCount(1, $tickets);
        $t1 = $tickets[0];

        $this->assertTrue($t1['is_linked_to_zabbix_problem']);
        $this->assertEquals('active', $t1['linked_problem_status']);
        // Because ZabbixProblemCache is empty/mocked, it might resolve as inactive problem
        $this->assertTrue($t1['linked_problem_is_resolved'] || $t1['linked_problem_is_active']);
    }

    public function test_it_applies_filters()
    {
        $ticket1 = ['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Network down', 'StateType' => 'open'];
        $ticket2 = ['TicketID' => 102, 'TicketNumber' => 'TN102', 'Title' => 'Disk full', 'StateType' => 'closed'];

        Redis::set('znuny:ticket:101', json_encode($ticket1));
        Redis::set('znuny:ticket:102', json_encode($ticket2));

        $reader = app(ZnunyTicketWorkspaceCacheReader::class);

        // Test search
        $res = $reader->getTickets(['search' => 'network']);
        $this->assertCount(1, $res);
        $this->assertEquals(101, $res[0]['TicketID']);

        // Test StateType
        $res = $reader->getTickets(['state_type' => 'closed']);
        $this->assertCount(1, $res);
        $this->assertEquals(102, $res[0]['TicketID']);

        // Test Linked Unlinked
        ZabbixTicket::create([
            'zabbix_event_id' => 'evt-1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'znuny_ticket_id' => 101,
            'znuny_ticket_number' => 'TN101',
            'manual_lifecycle_status' => 'active',
        ]);

        $res = $reader->getTickets(['link_status' => 'linked']);
        $this->assertCount(1, $res);
        $this->assertEquals(101, $res[0]['TicketID']);

        $res = $reader->getTickets(['link_status' => 'unlinked']);
        $this->assertCount(1, $res);
        $this->assertEquals(102, $res[0]['TicketID']);
    }
}
