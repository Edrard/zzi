<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\Setting;
use App\Models\ZabbixTicket;
use App\Services\SettingsService;
use App\Services\Zabbix\ZabbixProblemCache;
use App\Services\Znuny\ZnunyTicketCacheService;
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
        SettingsService::clearAllCaches();
        Redis::flushall();
    }

    protected function tearDown(): void
    {
        SettingsService::clearAllCaches();
        parent::tearDown();
    }

    protected function seedTicket(array $ticketOverrides): void
    {
        $ticket = array_merge([
            'TicketID' => 1,
            'TicketNumber' => '100000000',
            'Title' => 'Default',
            'QueueID' => 1,
            'Queue' => 'Raw',
            'OwnerID' => 1,
            'Owner' => 'Admin',
            'StateID' => 1,
            'State' => 'new',
            'StateType' => 'new',
            'PriorityID' => 1,
            'Priority' => '3 normal',
            'TypeID' => 1,
            'Type' => 'Unclassified',
            'Changed' => now()->toDateTimeString(),
            'Created' => now()->subDay()->toDateTimeString(),
            'ArticleCount' => 1,
        ], $ticketOverrides);

        app(ZnunyTicketCacheService::class)->upsertOrRefreshFromSearchResult($ticket);
    }

    public function test_it_returns_cached_tickets_and_handles_prefixes()
    {
        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'First', 'StateType' => 'open']);
        $this->seedTicket(['TicketID' => 102, 'TicketNumber' => 'TN102', 'Title' => 'Second', 'StateType' => 'closed']);

        // Add a malformed one manually
        Redis::set('znuny:ticket:103', 'not-a-json');

        $reader = app(ZnunyTicketWorkspaceCacheReader::class);
        $res = $reader->getTicketsPaginated(['state_types' => ['open', 'closed']], 1, 50);
        $tickets = $res['rows'];

        // 103 is ignored
        $this->assertCount(2, $tickets);

        $ids = array_column($tickets, 'TicketID');
        $this->assertContains(101, $ids);
        $this->assertContains(102, $ids);

        // Ensure defaults are set
        $t1 = collect($tickets)->firstWhere('TicketID', 101);
        $this->assertFalse($t1['is_linked_to_zabbix_problem']);
    }

    public function test_returns_empty_when_workspace_disabled()
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['value' => 'true']);
        SettingsService::clearAllCaches();

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'First', 'StateType' => 'open']);

        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['value' => 'false']);
        SettingsService::clearAllCaches();

        $reader = app(ZnunyTicketWorkspaceCacheReader::class);
        $res = $reader->getTicketsPaginated(['state_types' => ['open']], 1, 50);

        $this->assertCount(0, $res['rows']);
        $this->assertEquals(0, $res['total']);
        $this->assertNotNull(Redis::get('znuny:ticket:101'));
    }

    public function test_reenable_restores_data()
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['value' => 'true']);
        SettingsService::clearAllCaches();

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'First', 'StateType' => 'open']);

        $reader = app(ZnunyTicketWorkspaceCacheReader::class);

        $res = $reader->getTicketsPaginated(['state_types' => ['open']], 1, 50);
        $this->assertCount(1, $res['rows']);

        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['value' => 'false']);
        SettingsService::clearAllCaches();

        $res = $reader->getTicketsPaginated(['state_types' => ['open']], 1, 50);
        $this->assertCount(0, $res['rows']);

        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['value' => 'true']);
        SettingsService::clearAllCaches();

        $res = $reader->getTicketsPaginated(['state_types' => ['open']], 1, 50);
        $this->assertCount(1, $res['rows']);
        $this->assertEquals(101, $res['rows'][0]['TicketID']);
    }

    public function test_it_enriches_tickets_with_local_zabbix_links()
    {
        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'First', 'StateType' => 'open']);

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
        $res = $reader->getTicketsPaginated(['state_types' => ['open']], 1, 50);
        $tickets = $res['rows'];

        $this->assertCount(1, $tickets);
        $t1 = $tickets[0];

        $this->assertTrue($t1['is_linked_to_zabbix_problem']);
        $this->assertEquals('active', $t1['linked_problem_status']);
        // Because ZabbixProblemCache is empty/mocked, it resolves as inactive problem falling back to db fields
        $this->assertTrue($t1['linked_problem_is_resolved']);
        $this->assertEquals('Host 1', $t1['linked_problem_host']);
        $this->assertEquals('Problem 1', $t1['linked_problem_summary']);
    }

    public function test_it_applies_filters()
    {
        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Network down', 'StateType' => 'open', 'QueueID' => 10, 'OwnerID' => 20]);
        $this->seedTicket(['TicketID' => 102, 'TicketNumber' => 'TN102', 'Title' => 'Disk full', 'StateType' => 'closed', 'QueueID' => 11, 'OwnerID' => 21]);

        $reader = app(ZnunyTicketWorkspaceCacheReader::class);

        // Test search
        $res = $reader->getTicketsPaginated(['search' => 'network', 'state_types' => ['open', 'closed']], 1, 50);
        $this->assertCount(1, $res['rows']);
        $this->assertEquals(101, $res['rows'][0]['TicketID']);

        // Test StateType
        $res = $reader->getTicketsPaginated(['state_types' => ['closed']], 1, 50);
        $this->assertCount(1, $res['rows']);
        $this->assertEquals(102, $res['rows'][0]['TicketID']);

        // Test Queue
        $res = $reader->getTicketsPaginated(['state_types' => ['open', 'closed'], 'queue' => 10], 1, 50);
        $this->assertCount(1, $res['rows']);
        $this->assertEquals(101, $res['rows'][0]['TicketID']);

        // Test Owner
        $res = $reader->getTicketsPaginated(['state_types' => ['open', 'closed'], 'owner' => 21], 1, 50);
        $this->assertCount(1, $res['rows']);
        $this->assertEquals(102, $res['rows'][0]['TicketID']);

        // Test Linked Unlinked
        ZabbixTicket::create([
            'zabbix_event_id' => 'evt-1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'znuny_ticket_id' => 101,
            'znuny_ticket_number' => 'TN101',
            'manual_lifecycle_status' => 'active',
        ]);

        $res = $reader->getTicketsPaginated(['link_status' => 'linked', 'state_types' => ['open', 'closed']], 1, 50);
        $this->assertCount(1, $res['rows']);
        $this->assertEquals(101, $res['rows'][0]['TicketID']);

        $res = $reader->getTicketsPaginated(['link_status' => 'unlinked', 'state_types' => ['open', 'closed']], 1, 50);
        $this->assertCount(1, $res['rows']);
        $this->assertEquals(102, $res['rows'][0]['TicketID']);
    }

    public function test_linked_problem_warning_logic()
    {
        $this->mock(ZabbixProblemCache::class, function ($mock) {
            $mock->shouldReceive('find')->with('evt-1')->andReturn(['name' => 'Active Problem 1', 'severity' => 2]);
            $mock->shouldReceive('find')->with('evt-2')->andReturn(['name' => 'Active Problem 2', 'severity' => 2]);
            $mock->shouldReceive('find')->with('evt-3')->andReturn(['name' => 'Active Problem 3', 'severity' => 2]);
            $mock->shouldReceive('find')->with('evt-99')->andReturn(null);
        });

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'New', 'StateType' => 'new']);
        $this->seedTicket(['TicketID' => 102, 'TicketNumber' => 'TN102', 'Title' => 'Open', 'StateType' => 'open']);
        $this->seedTicket(['TicketID' => 103, 'TicketNumber' => 'TN103', 'Title' => 'Closed', 'StateType' => 'closed']);
        $this->seedTicket(['TicketID' => 104, 'TicketNumber' => 'TN104', 'Title' => 'Resolved', 'StateType' => 'closed']);

        ZabbixTicket::create([
            'zabbix_event_id' => 'evt-1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'znuny_ticket_id' => 101,
            'znuny_ticket_number' => 'TN101',
        ]);
        ZabbixTicket::create([
            'zabbix_event_id' => 'evt-2',
            'zabbix_host_name' => 'Host 2',
            'zabbix_problem_name' => 'Problem 2',
            'znuny_ticket_id' => 102,
            'znuny_ticket_number' => 'TN102',
        ]);
        ZabbixTicket::create([
            'zabbix_event_id' => 'evt-3',
            'zabbix_host_name' => 'Host 3',
            'zabbix_problem_name' => 'Problem 3',
            'znuny_ticket_id' => 103,
            'znuny_ticket_number' => 'TN103',
        ]);
        ZabbixTicket::create([
            'zabbix_event_id' => 'evt-99',
            'zabbix_host_name' => 'Host 99',
            'zabbix_problem_name' => 'Problem 99',
            'znuny_ticket_id' => 104,
            'znuny_ticket_number' => 'TN104',
        ]);

        $reader = app(ZnunyTicketWorkspaceCacheReader::class);
        $res = $reader->getTicketsPaginated(['state_types' => ['new', 'open', 'closed']], 1, 50);
        $tickets = collect($res['rows'])->keyBy('TicketID');

        // 1. Active linked problem with StateType: new does not set warning
        $t101 = $tickets[101];
        $this->assertTrue($t101['linked_problem_is_active']);
        $this->assertFalse($t101['linked_problem_is_resolved']);
        $this->assertFalse($t101['linked_problem_has_warning']);

        // 2. Active linked problem with StateType: open does not set warning
        $t102 = $tickets[102];
        $this->assertTrue($t102['linked_problem_is_active']);
        $this->assertFalse($t102['linked_problem_has_warning']);

        // 3. Active linked problem with StateType: closed DOES set warning
        $t103 = $tickets[103];
        $this->assertTrue($t103['linked_problem_is_active']);
        $this->assertTrue($t103['linked_problem_has_warning']);

        // 4. Resolved/missing active problem keeps warning false
        $t104 = $tickets[104];
        $this->assertFalse($t104['linked_problem_is_active']);
        $this->assertTrue($t104['linked_problem_is_resolved']);
        $this->assertFalse($t104['linked_problem_has_warning']);
    }
}
