<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\Setting;
use App\Models\ZabbixTicket;
use App\Services\SettingsService;
use App\Services\Zabbix\ZabbixProblemCache;
use App\Services\Znuny\ClosedTicketCacheService;
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
        Redis::flushdb();
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

    public function test_it_normalizes_inline_attachment_count()
    {
        $this->seedTicket(['TicketID' => 201, 'StateType' => 'open', 'InlineAttachmentCount' => 3]);
        $this->seedTicket(['TicketID' => 202, 'StateType' => 'open', 'InlineAttachmentCount' => '3']);
        $this->seedTicket(['TicketID' => 203, 'StateType' => 'open']);
        $this->seedTicket(['TicketID' => 204, 'StateType' => 'open', 'InlineAttachmentCount' => null]);
        $this->seedTicket(['TicketID' => 205, 'StateType' => 'open', 'InlineAttachmentCount' => -1]);
        $this->seedTicket(['TicketID' => 206, 'StateType' => 'open', 'InlineAttachmentCount' => '3abc']);
        $this->seedTicket(['TicketID' => 207, 'StateType' => 'open', 'InlineAttachmentCount' => 3.5]);
        $this->seedTicket(['TicketID' => 208, 'StateType' => 'open', 'InlineAttachmentCount' => true]);
        $this->seedTicket(['TicketID' => 209, 'StateType' => 'open', 'InlineAttachmentCount' => [3]]);

        $reader = app(ZnunyTicketWorkspaceCacheReader::class);
        $res = $reader->getTicketsPaginated(['state_types' => ['open']], 1, 50);
        $tickets = collect($res['rows'])->keyBy('TicketID');

        $this->assertEquals(3, $tickets[201]['InlineAttachmentCount']);
        $this->assertEquals(3, $tickets[202]['InlineAttachmentCount']);
        $this->assertEquals(0, $tickets[203]['InlineAttachmentCount']);
        $this->assertEquals(0, $tickets[204]['InlineAttachmentCount']);
        $this->assertEquals(0, $tickets[205]['InlineAttachmentCount']);
        $this->assertEquals(0, $tickets[206]['InlineAttachmentCount']);
        $this->assertEquals(0, $tickets[207]['InlineAttachmentCount']);
        $this->assertEquals(0, $tickets[208]['InlineAttachmentCount']);
        $this->assertEquals(0, $tickets[209]['InlineAttachmentCount']);
    }

    public function test_it_normalizes_customer_user_and_id_and_registration()
    {
        $reader = app(ZnunyTicketWorkspaceCacheReader::class);

        // 1. Registered non-email-login user
        $t1 = $reader->normalizeSingleTicket([
            'TicketID' => 301,
            'StateType' => 'open',
            'CustomerUserID' => 'PanlogisticClients',
            'CustomerID' => 'panlogistic',
            'customer_user_registered' => true,
        ]);

        $this->assertEquals('PanlogisticClients', $t1['CustomerUserID']);
        $this->assertEquals('panlogistic', $t1['CustomerID']);
        $this->assertTrue($t1['customer_user_registered']);

        // 2. Mail-only/unregistered
        $t2 = $reader->normalizeSingleTicket([
            'TicketID' => 302,
            'StateType' => 'open',
            'CustomerUserID' => 'oleksandr.ustinov@tmm.ua',
            'CustomerID' => 'oleksandr.ustinov@tmm.ua',
            'customer_user_registered' => false,
        ]);

        $this->assertEquals('oleksandr.ustinov@tmm.ua', $t2['CustomerUserID']);
        $this->assertEquals('oleksandr.ustinov@tmm.ua', $t2['CustomerID']);
        $this->assertFalse($t2['customer_user_registered']);

        // 3. Legacy (absent boolean)
        $t3 = $reader->normalizeSingleTicket([
            'TicketID' => 303,
            'StateType' => 'open',
            'CustomerUserID' => 'legacy',
            'CustomerID' => 'legacy',
        ]);

        $this->assertEquals('legacy', $t3['CustomerUserID']);
        $this->assertEquals('legacy', $t3['CustomerID']);
        $this->assertNull($t3['customer_user_registered']);
    }

    public function test_reader_ignores_stale_active_ids_in_total_and_rows(): void
    {
        $this->seedTicket(['TicketID' => 101, 'Title' => 'Live 1', 'StateType' => 'open']);
        $this->seedTicket(['TicketID' => 102, 'Title' => 'Live 2', 'StateType' => 'open']);

        // Stale entries in active state index
        Redis::zadd('znuny:index:statetype:open', time(), 103);
        Redis::zadd('znuny:index:statetype:open', time(), 104);
        // 103 has no payload, 104 has corrupt payload
        Redis::set('znuny:ticket:104', 'invalid-json');

        $reader = app(ZnunyTicketWorkspaceCacheReader::class);
        $res = $reader->getTicketsPaginated(['state_types' => ['open']], 1, 50);

        $this->assertSame(2, $res['total']);
        $this->assertCount(2, $res['rows']);
        $ids = array_column($res['rows'], 'TicketID');
        $this->assertEqualsCanonicalizing([101, 102], $ids);
    }

    public function test_missing_or_malformed_active_payloads_do_not_appear_in_queue_or_owner_options(): void
    {
        $this->seedTicket([
            'TicketID' => 101,
            'StateType' => 'open',
            'QueueID' => 5,
            'Queue' => 'Valid Queue',
            'OwnerID' => 10,
            'Owner' => 'Valid Owner',
        ]);

        // Stale ID in state, queue and owner indexes without payload
        Redis::zadd('znuny:index:statetype:open', time(), 999);
        Redis::zadd('znuny:index:queue:99', time(), 999);
        Redis::zadd('znuny:index:owner:99', time(), 999);

        // Corrupt payload
        Redis::zadd('znuny:index:statetype:open', time(), 998);
        Redis::set('znuny:ticket:998', json_encode(['Title' => 'Missing ID']));

        $reader = app(ZnunyTicketWorkspaceCacheReader::class);
        $res = $reader->getTicketsPaginated(['state_types' => ['open']], 1, 50);

        $this->assertSame(1, $res['total']);
        $this->assertArrayHasKey(5, $res['filter_options']['queues']);
        $this->assertArrayNotHasKey(99, $res['filter_options']['queues']);
        $this->assertArrayHasKey(10, $res['filter_options']['owners']);
        $this->assertArrayNotHasKey(99, $res['filter_options']['owners']);
    }

    public function test_reproduces_over_500_bug_and_ensures_owner_after_position_500_appears_in_options(): void
    {
        // Add 520 stale IDs to open state index
        for ($i = 1; $i <= 520; $i++) {
            Redis::zadd('znuny:index:statetype:open', time() + $i, $i);
        }

        // Live ticket beyond index position 500
        $this->seedTicket([
            'TicketID' => 550,
            'TicketNumber' => '2026091046000536',
            'Title' => 'Live Ticket Owner 3',
            'StateType' => 'open',
            'QueueID' => 7,
            'Queue' => 'Support',
            'OwnerID' => 3,
            'Owner' => 'andrushevych',
        ]);

        $this->seedTicket([
            'TicketID' => 560,
            'TicketNumber' => '2026090346000719',
            'Title' => 'Live Ticket Owner 4',
            'StateType' => 'open',
            'QueueID' => 8,
            'Queue' => 'DevOps',
            'OwnerID' => 4,
            'Owner' => 'developer',
        ]);

        $reader = app(ZnunyTicketWorkspaceCacheReader::class);
        $res = $reader->getTicketsPaginated(['state_types' => ['open']], 1, 50);

        // Total reflects ONLY live tickets (2 out of 522 indexed)
        $this->assertSame(2, $res['total']);
        $this->assertCount(2, $res['rows']);

        // OwnerID 3 and 4 MUST appear in owner options even though they were past position 500
        $this->assertArrayHasKey(3, $res['filter_options']['owners']);
        $this->assertArrayHasKey(4, $res['filter_options']['owners']);
        $this->assertArrayHasKey(7, $res['filter_options']['queues']);
        $this->assertArrayHasKey(8, $res['filter_options']['queues']);
    }

    public function test_faceted_semantics_remain_exact_for_queue_and_owner_filters(): void
    {
        $this->seedTicket(['TicketID' => 1, 'StateType' => 'open', 'QueueID' => 10, 'Queue' => 'Q10', 'OwnerID' => 20, 'Owner' => 'O20']);
        $this->seedTicket(['TicketID' => 2, 'StateType' => 'open', 'QueueID' => 10, 'Queue' => 'Q10', 'OwnerID' => 21, 'Owner' => 'O21']);
        $this->seedTicket(['TicketID' => 3, 'StateType' => 'open', 'QueueID' => 11, 'Queue' => 'Q11', 'OwnerID' => 20, 'Owner' => 'O20']);
        $this->seedTicket(['TicketID' => 4, 'StateType' => 'open', 'QueueID' => 12, 'Queue' => 'Q12', 'OwnerID' => 22, 'Owner' => 'O22']);

        $reader = app(ZnunyTicketWorkspaceCacheReader::class);

        // 1. Filter by Queue 10:
        // Owner options must respect Queue 10 (Owner 20 and 21), but NOT Owner 22
        // Queue options must NOT self-filter to only Queue 10 (all live queues 10, 11, 12 remain available)
        $resQueue = $reader->getTicketsPaginated(['state_types' => ['open'], 'queue' => 10], 1, 50);
        $this->assertSame(2, $resQueue['total']);
        $this->assertArrayHasKey(20, $resQueue['filter_options']['owners']);
        $this->assertArrayHasKey(21, $resQueue['filter_options']['owners']);
        $this->assertArrayNotHasKey(22, $resQueue['filter_options']['owners']);
        $this->assertArrayHasKey(10, $resQueue['filter_options']['queues']);
        $this->assertArrayHasKey(11, $resQueue['filter_options']['queues']);
        $this->assertArrayHasKey(12, $resQueue['filter_options']['queues']);

        // 2. Filter by Owner 20:
        // Queue options must respect Owner 20 (Queue 10 and 11), but NOT Queue 12
        // Owner options must NOT self-filter to only Owner 20 (all live owners 20, 21, 22 remain available)
        $resOwner = $reader->getTicketsPaginated(['state_types' => ['open'], 'owner' => 20], 1, 50);
        $this->assertSame(2, $resOwner['total']);
        $this->assertArrayHasKey(10, $resOwner['filter_options']['queues']);
        $this->assertArrayHasKey(11, $resOwner['filter_options']['queues']);
        $this->assertArrayNotHasKey(12, $resOwner['filter_options']['queues']);
        $this->assertArrayHasKey(20, $resOwner['filter_options']['owners']);
        $this->assertArrayHasKey(21, $resOwner['filter_options']['owners']);
        $this->assertArrayHasKey(22, $resOwner['filter_options']['owners']);

        // 3. Filter by both Queue 10 and Owner 20:
        // Exactly 1 row (Ticket 1)
        $resBoth = $reader->getTicketsPaginated(['state_types' => ['open'], 'queue' => 10, 'owner' => 20], 1, 50);
        $this->assertSame(1, $resBoth['total']);
        $this->assertSame(1, $resBoth['rows'][0]['TicketID']);
        // Selected filters remain stable/visible in dropdowns
        $this->assertArrayHasKey(10, $resBoth['filter_options']['queues']);
        $this->assertArrayHasKey(20, $resBoth['filter_options']['owners']);
    }

    public function test_stale_closed_index_id_does_not_affect_count_options_or_rows(): void
    {
        $closedService = app(ClosedTicketCacheService::class);
        $closedService->upsertTicket([
            'TicketID' => 501,
            'Title' => 'Real Closed',
            'StateType' => 'closed',
            'Created' => '2023-10-01 12:00:00',
            'QueueID' => 30,
            'Queue' => 'Closed Queue',
            'OwnerID' => 40,
            'Owner' => 'Closed Owner',
        ], 30);

        // Stale entry in closed daily index with no payload
        Redis::zadd('znuny:closed_ticket:index:2023-10-01', time(), 502);

        $reader = app(ZnunyTicketWorkspaceCacheReader::class);
        $res = $reader->getTicketsPaginated(['state_types' => ['closed']], 1, 50);

        $this->assertSame(1, $res['total']);
        $this->assertCount(1, $res['rows']);
        $this->assertSame(501, $res['rows'][0]['TicketID']);
        $this->assertArrayHasKey(30, $res['filter_options']['queues']);
        $this->assertArrayHasKey(40, $res['filter_options']['owners']);
    }

    public function test_reader_processes_all_candidates_without_5000_cap(): void
    {
        // Add 5100 candidate IDs into open state index
        $bulk = [];
        for ($i = 1; $i <= 5100; $i++) {
            $bulk[$i] = time() + $i;
        }
        Redis::zadd('znuny:index:statetype:open', $bulk);

        // Live ticket positioned past 5000 (ID 5050)
        $this->seedTicket([
            'TicketID' => 5050,
            'TicketNumber' => 'TN5050',
            'Title' => 'Ticket 5050',
            'StateType' => 'open',
            'QueueID' => 99,
            'Queue' => 'Special Queue',
            'OwnerID' => 88,
            'Owner' => 'Special Owner',
        ]);

        $reader = app(ZnunyTicketWorkspaceCacheReader::class);
        $res = $reader->getTicketsPaginated(['state_types' => ['open']], 1, 50);

        // Total must count the live ticket past 5000
        $this->assertSame(1, $res['total']);
        $this->assertCount(1, $res['rows']);
        $this->assertSame(5050, $res['rows'][0]['TicketID']);
        $this->assertArrayHasKey(99, $res['filter_options']['queues']);
        $this->assertArrayHasKey(88, $res['filter_options']['owners']);
    }

    public function test_queue_and_owner_facets_trust_payload_state_type_not_stale_state_index(): void
    {
        // Genuinely open live ticket
        $this->seedTicket([
            'TicketID' => 101,
            'TicketNumber' => 'TN101',
            'Title' => 'Open Ticket',
            'StateType' => 'open',
            'QueueID' => 10,
            'Queue' => 'Open Queue',
            'OwnerID' => 20,
            'Owner' => 'Open Owner',
        ]);

        // Stale index membership: Ticket payload is closed, but ID 102 is also in open state index
        $this->seedTicket([
            'TicketID' => 102,
            'TicketNumber' => 'TN102',
            'Title' => 'Closed Ticket In Stale Open Index',
            'StateType' => 'closed',
            'QueueID' => 11,
            'Queue' => 'Closed Queue',
            'OwnerID' => 21,
            'Owner' => 'Closed Owner',
        ]);
        Redis::zadd('znuny:index:statetype:open', time(), 102);

        $reader = app(ZnunyTicketWorkspaceCacheReader::class);
        $res = $reader->getTicketsPaginated(['state_types' => ['open']], 1, 50);

        // Total and rows show ONLY genuinely open ticket
        $this->assertSame(1, $res['total']);
        $this->assertCount(1, $res['rows']);
        $this->assertSame(101, $res['rows'][0]['TicketID']);

        // Queue/Owner options from the closed payload must NOT appear
        $this->assertArrayHasKey(10, $res['filter_options']['queues']);
        $this->assertArrayNotHasKey(11, $res['filter_options']['queues']);
        $this->assertArrayHasKey(20, $res['filter_options']['owners']);
        $this->assertArrayNotHasKey(21, $res['filter_options']['owners']);
    }

    public function test_reader_ignores_payload_when_key_ticket_id_does_not_match_payload_ticket_id(): void
    {
        // Add candidate ID 123 into open state index
        Redis::zadd('znuny:index:statetype:open', time(), 123);

        // Payload has TicketID 456 instead of 123 (mismatched)
        Redis::set('znuny:ticket:123', json_encode([
            'TicketID' => 456,
            'TicketNumber' => 'TN456',
            'Title' => 'Mismatched Ticket',
            'StateType' => 'open',
            'QueueID' => 5,
            'Queue' => 'Queue 5',
            'OwnerID' => 6,
            'Owner' => 'Owner 6',
        ]));

        $reader = app(ZnunyTicketWorkspaceCacheReader::class);
        $res = $reader->getTicketsPaginated(['state_types' => ['open']], 1, 50);

        // Mismatched payload is discarded
        $this->assertSame(0, $res['total']);
        $this->assertEmpty($res['rows']);
        $this->assertArrayNotHasKey(5, $res['filter_options']['queues']);
        $this->assertArrayNotHasKey(6, $res['filter_options']['owners']);
    }
}
