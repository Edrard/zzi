<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\Setting;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use App\Services\Znuny\ZnunyTicketCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ZnunyTicketCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    private ZnunyTicketCacheService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Redis calls
        Redis::shouldReceive('setex')->byDefault();
        Redis::shouldReceive('get')->andReturnNull()->byDefault();
        Redis::shouldReceive('del')->byDefault();
        Redis::shouldReceive('zadd')->byDefault();
        Redis::shouldReceive('zrem')->byDefault();
        Redis::shouldReceive('expire')->byDefault();

        // Ensure settings pretend cache is enabled
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['value' => 'true']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_cache_ttl_minutes'], ['value' => '10']);

        $mockLookup = \Mockery::mock(ZnunyLookupCacheReadService::class);
        $mockLookup->shouldReceive('hasCustomerCompany')->andReturn(false)->byDefault();
        $this->service = new ZnunyTicketCacheService($mockLookup);
    }

    public function test_it_does_not_cache_if_disabled(): void
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['value' => 'false']);

        Redis::shouldReceive('setex')->never();

        $this->service->upsertTicket(['TicketID' => 123]);
    }

    public function test_it_applies_active_ttl_guard_when_configured_ttl_is_less_than_safe_ttl(): void
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_cache_ttl_minutes'], ['value' => '5']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_cache_refresh_interval_minutes'], ['value' => '5']);
        config(['app.ui_poll_interval_seconds' => 60]);

        $ticket = [
            'TicketID' => 101,
            'StateType' => 'open',
            'QueueID' => 5,

        ];

        // Safe TTL = (5 * 60) + 60 = 360
        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket:101', 360, json_encode(array_merge($ticket, ['customer_user_registered' => false])));

        // And reverse index
        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket_indexes:101', \Mockery::any(), \Mockery::any());

        Redis::shouldReceive('zadd')->times(2); // state type and queue
        Redis::shouldReceive('expire')->times(2);

        $this->service->upsertTicket($ticket);
    }

    public function test_it_uses_configured_active_ttl_when_greater_than_safe_ttl(): void
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_cache_ttl_minutes'], ['value' => '10']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_cache_refresh_interval_minutes'], ['value' => '5']);
        config(['app.ui_poll_interval_seconds' => 60]);

        $ticket = [
            'TicketID' => 101,
            'StateType' => 'open',
            'QueueID' => 5,

        ];

        // Configured TTL = 10 * 60 = 600, Safe TTL = (5 * 60) + 60 = 360. Max = 600.
        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket:101', 600, json_encode(array_merge($ticket, ['customer_user_registered' => false])));

        // And reverse index
        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket_indexes:101', \Mockery::any(), \Mockery::any());

        Redis::shouldReceive('zadd')->times(2); // state type and queue
        Redis::shouldReceive('expire')->times(2);

        $this->service->upsertTicket($ticket);
    }

    public function test_it_caches_closed_ticket_with_active_ttl(): void
    {
        $ticket = [
            'TicketID' => 102,
            'StateType' => 'closed',

        ];

        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket:102', 600, json_encode(array_merge($ticket, ['customer_user_registered' => false])));

        // And reverse index
        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket_indexes:102', \Mockery::any(), \Mockery::any());

        Redis::shouldReceive('zadd')->times(1);
        Redis::shouldReceive('expire')->times(1);

        $this->service->upsertTicket($ticket);
    }

    public function test_it_retrieves_cached_ticket(): void
    {
        $ticket = ['TicketID' => 200, 'Title' => 'Test',

        ];

        Redis::shouldReceive('get')
            ->with('znuny:ticket:200')
            ->andReturn(json_encode(array_merge($ticket, ['customer_user_registered' => false])));

        $result = $this->service->getTicket(200);

        $expected = array_merge($ticket, ['customer_user_registered' => false]);
        $this->assertEquals($expected, $result);
    }

    public function test_forget_ticket_removes_from_cache_and_indexes(): void
    {
        Redis::shouldReceive('del')
            ->once()
            ->with('znuny:ticket:300');

        // Reverse index lookup
        Redis::shouldReceive('get')
            ->with('znuny:ticket_indexes:300')
            ->andReturn(json_encode(['znuny:index:queue:1']));

        Redis::shouldReceive('zrem')
            ->once()
            ->with('znuny:index:queue:1', 300);

        Redis::shouldReceive('del')
            ->once()
            ->with('znuny:ticket_indexes:300');

        $this->service->forgetTicket(300);
    }

    public function test_mark_closed_with_short_ttl_updates_cache(): void
    {
        $ticket = [
            'TicketID' => 400,
            'StateType' => 'closed',

        ];

        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket:400', 600, json_encode(array_merge($ticket, ['customer_user_registered' => false])));

        // And reverse index
        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket_indexes:400', \Mockery::any(), \Mockery::any());

        Redis::shouldReceive('zadd')->times(1);
        Redis::shouldReceive('expire')->times(1);

        $this->service->markClosedWithShortTtl($ticket);
    }

    public function test_upsert_or_refresh_skipped_missing_ticket_id(): void
    {
        $result = $this->service->upsertOrRefreshFromSearchResult([]);
        $this->assertEquals('skipped_missing_ticket_id', $result);
    }

    public function test_upsert_or_refresh_cached_new(): void
    {
        $ticket = [
            'TicketID' => 500,
            'SyncFingerprint' => 'fp1',

        ];

        Redis::shouldReceive('get')->with('znuny:ticket:500')->andReturn(null);

        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket:500', 600, json_encode(array_merge($ticket, ['customer_user_registered' => false])));

        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket_indexes:500', \Mockery::any(), \Mockery::any());

        $result = $this->service->upsertOrRefreshFromSearchResult($ticket);
        $this->assertEquals('cached_new', $result);
    }

    public function test_upsert_or_refresh_refreshed_unchanged(): void
    {
        $ticket = [
            'TicketID' => 600,
            'SyncFingerprint' => 'fp_same',
            'InlineAttachmentCount' => 0,
            'HTMLBodyArticleCount' => 0,

        ];

        $existing = json_encode(['TicketID' => 600, 'SyncFingerprint' => 'fp_same', 'InlineAttachmentCount' => 0, 'HTMLBodyArticleCount' => 0, 'customer_user_registered' => false]);

        Redis::shouldReceive('get')->with('znuny:ticket:600')->andReturn($existing);
        Redis::shouldReceive('get')->with('znuny:ticket_indexes:600')->andReturn(json_encode(['znuny:index:queue:1']));

        // Should just expire
        Redis::shouldReceive('expire')->with('znuny:ticket:600', 600)->once();
        Redis::shouldReceive('expire')->with('znuny:ticket_indexes:600', \Mockery::any())->once();
        Redis::shouldReceive('expire')->with('znuny:index:queue:1', \Mockery::any())->once();

        // Should not set payload or rebuild indexes
        Redis::shouldReceive('setex')->never();
        Redis::shouldReceive('zadd')->never();

        $result = $this->service->upsertOrRefreshFromSearchResult($ticket);
        $this->assertEquals('refreshed_unchanged', $result);
    }

    public function test_upsert_or_refresh_updated_changed(): void
    {
        $ticket = [
            'TicketID' => 700,
            'SyncFingerprint' => 'fp_new',

        ];

        $existing = json_encode(['TicketID' => 700, 'SyncFingerprint' => 'fp_old', 'customer_user_registered' => false]);

        Redis::shouldReceive('get')->with('znuny:ticket:700')->andReturn($existing);
        Redis::shouldReceive('get')->with('znuny:ticket_indexes:700')->andReturn(json_encode(['znuny:index:queue:1']));

        Redis::shouldReceive('zrem')->with('znuny:index:queue:1', 700)->once();

        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket:700', 600, json_encode(array_merge($ticket, ['customer_user_registered' => false])));

        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket_indexes:700', \Mockery::any(), \Mockery::any());

        $result = $this->service->upsertOrRefreshFromSearchResult($ticket);
        $this->assertEquals('updated_changed', $result);
    }

    public function test_index_keys_for_ticket_includes_new_fields(): void
    {
        $ticket = [
            'TicketID' => 800,
            'QueueID' => 1,
            'OwnerID' => 2,
            'StateID' => 3,
            'StateType' => 'open',
            'PriorityID' => 4,
            'TypeID' => 5,
            'ServiceID' => 6,
            'SLAID' => 7,

        ];

        $keys = $this->service->indexKeysForTicket($ticket);

        $this->assertContains('znuny:index:queue:1', $keys);
        $this->assertContains('znuny:index:owner:2', $keys);
        $this->assertContains('znuny:index:state:3', $keys);
        $this->assertContains('znuny:index:statetype:open', $keys);
        $this->assertContains('znuny:index:priority:4', $keys);
        $this->assertContains('znuny:index:type:5', $keys);
        $this->assertContains('znuny:index:service:6', $keys);
        $this->assertContains('znuny:index:sla:7', $keys);
    }

    public function test_upsert_or_refresh_refreshed_unchanged_with_inline_attachment_count(): void
    {
        $ticket = [
            'TicketID' => 601,
            'SyncFingerprint' => 'fp_same',
            'InlineAttachmentCount' => 3,
            'HTMLBodyArticleCount' => 2,

        ];

        $existing = json_encode(['TicketID' => 601, 'SyncFingerprint' => 'fp_same', 'InlineAttachmentCount' => 3, 'HTMLBodyArticleCount' => 2, 'customer_user_registered' => false]);

        Redis::shouldReceive('get')->with('znuny:ticket:601')->andReturn($existing);
        Redis::shouldReceive('get')->with('znuny:ticket_indexes:601')->andReturn(json_encode(['znuny:index:queue:1']));

        // Should just expire
        Redis::shouldReceive('expire')->with('znuny:ticket:601', 600)->once();
        Redis::shouldReceive('expire')->with('znuny:ticket_indexes:601', \Mockery::any())->once();
        Redis::shouldReceive('expire')->with('znuny:index:queue:1', \Mockery::any())->once();

        // Should not set payload or rebuild indexes
        Redis::shouldReceive('setex')->never();
        Redis::shouldReceive('zadd')->never();

        $result = $this->service->upsertOrRefreshFromSearchResult($ticket);
        $this->assertEquals('refreshed_unchanged', $result);
    }

    public function test_upsert_or_refresh_updated_changed_with_different_inline_attachment_count(): void
    {
        $ticket = [
            'TicketID' => 701,
            'SyncFingerprint' => 'fp_same',
            'InlineAttachmentCount' => 3,
            'HTMLBodyArticleCount' => 2,

        ];

        $existing = json_encode(['TicketID' => 701, 'SyncFingerprint' => 'fp_same', 'InlineAttachmentCount' => 2, 'HTMLBodyArticleCount' => 2, 'customer_user_registered' => false]);

        Redis::shouldReceive('get')->with('znuny:ticket:701')->andReturn($existing);
        Redis::shouldReceive('get')->with('znuny:ticket_indexes:701')->andReturn(json_encode(['znuny:index:queue:1']));
        Redis::shouldReceive('zrem')->with('znuny:index:queue:1', 701)->once();

        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket:701', 600, json_encode(array_merge($ticket, ['customer_user_registered' => false])));

        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket_indexes:701', \Mockery::any(), \Mockery::any());

        $result = $this->service->upsertOrRefreshFromSearchResult($ticket);
        $this->assertEquals('updated_changed', $result);
    }

    public function test_upsert_or_refresh_updated_changed_when_inline_attachment_count_is_missing_in_existing(): void
    {
        $ticket = [
            'TicketID' => 702,
            'SyncFingerprint' => 'fp_same',
            'InlineAttachmentCount' => 0, // Incoming count 0
            'HTMLBodyArticleCount' => 0,

        ];

        // Legacy cached payload without InlineAttachmentCount
        $existing = json_encode(['TicketID' => 702, 'SyncFingerprint' => 'fp_same', 'customer_user_registered' => false]);

        Redis::shouldReceive('get')->with('znuny:ticket:702')->andReturn($existing);
        Redis::shouldReceive('get')->with('znuny:ticket_indexes:702')->andReturn(json_encode(['znuny:index:queue:1']));
        Redis::shouldReceive('zrem')->with('znuny:index:queue:1', 702)->once();

        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket:702', 600, json_encode(array_merge($ticket, ['customer_user_registered' => false])));

        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket_indexes:702', \Mockery::any(), \Mockery::any());

        $result = $this->service->upsertOrRefreshFromSearchResult($ticket);
        $this->assertEquals('updated_changed', $result);
    }

    public function test_upsert_or_refresh_treats_malformed_existing_payload_as_cached_new_or_updated(): void
    {
        $ticket = [
            'TicketID' => 703,
            'SyncFingerprint' => 'fp_same',
            'InlineAttachmentCount' => 3,
            'HTMLBodyArticleCount' => 2,

        ];

        // Malformed non-array JSON payload
        $existing = '"not-an-array"';

        Redis::shouldReceive('get')->with('znuny:ticket:703')->andReturn($existing);
        Redis::shouldReceive('get')->with('znuny:ticket_indexes:703')->andReturn(null);

        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket:703', 600, json_encode(array_merge($ticket, ['customer_user_registered' => false])));

        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket_indexes:703', \Mockery::any(), \Mockery::any());

        $result = $this->service->upsertOrRefreshFromSearchResult($ticket);
        $this->assertEquals('updated_changed', $result);
    }

    public function test_upsert_or_refresh_strict_count_comparison_with_invalid_existing_value(): void
    {
        $ticket = [
            'TicketID' => 704,
            'SyncFingerprint' => 'fp_same',
            'InlineAttachmentCount' => 0,
            'HTMLBodyArticleCount' => 0,

        ];

        // existing value is explicitly invalid (e.g. string that does not cast cleanly)
        $existing = json_encode(['TicketID' => 704, 'SyncFingerprint' => 'fp_same', 'InlineAttachmentCount' => '3abc', 'HTMLBodyArticleCount' => '2xyz', 'customer_user_registered' => false]);

        Redis::shouldReceive('get')->with('znuny:ticket:704')->andReturn($existing);
        Redis::shouldReceive('get')->with('znuny:ticket_indexes:704')->andReturn(json_encode(['znuny:index:queue:1']));

        // Should just expire because both normalize to 0
        Redis::shouldReceive('expire')->with('znuny:ticket:704', 600)->once();
        Redis::shouldReceive('expire')->with('znuny:ticket_indexes:704', \Mockery::any())->once();
        Redis::shouldReceive('expire')->with('znuny:index:queue:1', \Mockery::any())->once();

        Redis::shouldReceive('setex')->never();
        Redis::shouldReceive('zadd')->never();

        $result = $this->service->upsertOrRefreshFromSearchResult($ticket);
        $this->assertEquals('refreshed_unchanged', $result);
    }

    public function test_upsert_or_refresh_updates_when_html_body_article_count_is_missing_in_existing(): void
    {
        $ticket = [
            'TicketID' => 705,
            'SyncFingerprint' => 'fp_same',
            'InlineAttachmentCount' => 0,
            'HTMLBodyArticleCount' => 2,

        ];

        $existing = json_encode([
            'TicketID' => 705,
            'SyncFingerprint' => 'fp_same',
            'InlineAttachmentCount' => 0,

        ]);

        Redis::shouldReceive('get')->with('znuny:ticket:705')->andReturn($existing);
        Redis::shouldReceive('get')->with('znuny:ticket_indexes:705')->andReturn(json_encode(['znuny:index:queue:1']));
        Redis::shouldReceive('zrem')->with('znuny:index:queue:1', 705)->once();
        Redis::shouldReceive('setex')->with('znuny:ticket:705', 600, json_encode(array_merge($ticket, ['customer_user_registered' => false])))->once();
        Redis::shouldReceive('setex')->with('znuny:ticket_indexes:705', \Mockery::any(), \Mockery::any())->once();

        $result = $this->service->upsertOrRefreshFromSearchResult($ticket);

        $this->assertEquals('updated_changed', $result);
    }

    public function test_upsert_or_refresh_updates_when_html_body_article_count_changes(): void
    {
        $ticket = [
            'TicketID' => 706,
            'SyncFingerprint' => 'fp_same',
            'InlineAttachmentCount' => 0,
            'HTMLBodyArticleCount' => 2,
        ];

        $existing = json_encode([
            'TicketID' => 706,
            'SyncFingerprint' => 'fp_same',
            'InlineAttachmentCount' => 0,
            'HTMLBodyArticleCount' => 1,
            'customer_user_registered' => false,
        ]);

        Redis::shouldReceive('get')->with('znuny:ticket:706')->andReturn($existing);
        Redis::shouldReceive('get')->with('znuny:ticket_indexes:706')->andReturn(json_encode(['znuny:index:queue:1']));
        Redis::shouldReceive('zrem')->with('znuny:index:queue:1', 706)->once();
        Redis::shouldReceive('setex')->with('znuny:ticket:706', 600, json_encode(['TicketID' => 706, 'SyncFingerprint' => 'fp_same', 'InlineAttachmentCount' => 0, 'HTMLBodyArticleCount' => 2, 'customer_user_registered' => false]))->once();
        Redis::shouldReceive('setex')->with('znuny:ticket_indexes:706', \Mockery::any(), \Mockery::any())->once();

        $result = $this->service->upsertOrRefreshFromSearchResult($ticket);

        $this->assertEquals('updated_changed', $result);
    }

    public function test_upsert_or_refresh_registered_creates_true()
    {
        $mockLookup = \Mockery::mock(ZnunyLookupCacheReadService::class);
        $mockLookup->shouldReceive('hasCustomerCompany')->with('agrotekhnik')->andReturn(true);
        $service = new ZnunyTicketCacheService($mockLookup);

        $ticket = [
            'TicketID' => 1000,
            'CustomerID' => 'agrotekhnik',
            'SyncFingerprint' => 'fp1',
        ];

        Redis::shouldReceive('get')->with('znuny:ticket:1000')->andReturn(null);
        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket:1000', 600, json_encode(['TicketID' => 1000, 'CustomerID' => 'agrotekhnik', 'SyncFingerprint' => 'fp1', 'customer_user_registered' => true]));
        Redis::shouldReceive('setex')->with('znuny:ticket_indexes:1000', \Mockery::any(), \Mockery::any());

        $result = $service->upsertOrRefreshFromSearchResult($ticket);
        $this->assertEquals('cached_new', $result);
    }

    public function test_upsert_or_refresh_mail_only_creates_false()
    {
        $ticket = [
            'TicketID' => 1001,
            'CustomerID' => 'oleksandr.ustinov@tmm.ua',
            'SyncFingerprint' => 'fp1',
        ];

        Redis::shouldReceive('get')->with('znuny:ticket:1001')->andReturn(null);
        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket:1001', 600, json_encode(['TicketID' => 1001, 'CustomerID' => 'oleksandr.ustinov@tmm.ua', 'SyncFingerprint' => 'fp1', 'customer_user_registered' => false]));
        Redis::shouldReceive('setex')->with('znuny:ticket_indexes:1001', \Mockery::any(), \Mockery::any());

        $result = $this->service->upsertOrRefreshFromSearchResult($ticket);
        $this->assertEquals('cached_new', $result);
    }

    public function test_upsert_or_refresh_old_cache_migration_rewrites()
    {
        $ticket = [
            'TicketID' => 1002,
            'CustomerID' => 'oleksandr.ustinov@tmm.ua',
            'SyncFingerprint' => 'fp_same',
            'InlineAttachmentCount' => 0,
            'HTMLBodyArticleCount' => 0,
        ];
        // Missing customer_user_registered in cache
        $existing = json_encode(['TicketID' => 1002, 'CustomerID' => 'oleksandr.ustinov@tmm.ua', 'SyncFingerprint' => 'fp_same', 'InlineAttachmentCount' => 0, 'HTMLBodyArticleCount' => 0]);

        Redis::shouldReceive('get')->with('znuny:ticket:1002')->andReturn($existing);
        Redis::shouldReceive('get')->with('znuny:ticket_indexes:1002')->andReturn(json_encode(['znuny:index:queue:1']));
        Redis::shouldReceive('zrem')->with('znuny:index:queue:1', 1002)->once();

        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket:1002', 600, json_encode(['TicketID' => 1002, 'CustomerID' => 'oleksandr.ustinov@tmm.ua', 'SyncFingerprint' => 'fp_same', 'InlineAttachmentCount' => 0, 'HTMLBodyArticleCount' => 0, 'customer_user_registered' => false]));
        Redis::shouldReceive('setex')->with('znuny:ticket_indexes:1002', \Mockery::any(), \Mockery::any());

        $result = $this->service->upsertOrRefreshFromSearchResult($ticket);
        $this->assertEquals('updated_changed', $result);
    }

    public function test_upsert_or_refresh_registration_changed_rewrites()
    {
        $mockLookup = \Mockery::mock(ZnunyLookupCacheReadService::class);
        $mockLookup->shouldReceive('hasCustomerCompany')->with('agrotekhnik')->andReturn(true);
        $service = new ZnunyTicketCacheService($mockLookup);

        $ticket = [
            'TicketID' => 1003,
            'CustomerID' => 'agrotekhnik',
            'SyncFingerprint' => 'fp_same',
            'InlineAttachmentCount' => 0,
            'HTMLBodyArticleCount' => 0,
        ];
        // Exists in cache but with false
        $existing = json_encode(['TicketID' => 1003, 'CustomerID' => 'agrotekhnik', 'SyncFingerprint' => 'fp_same', 'InlineAttachmentCount' => 0, 'HTMLBodyArticleCount' => 0, 'customer_user_registered' => false]);

        Redis::shouldReceive('get')->with('znuny:ticket:1003')->andReturn($existing);
        Redis::shouldReceive('get')->with('znuny:ticket_indexes:1003')->andReturn(json_encode(['znuny:index:queue:1']));
        Redis::shouldReceive('zrem')->with('znuny:index:queue:1', 1003)->once();

        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket:1003', 600, json_encode(['TicketID' => 1003, 'CustomerID' => 'agrotekhnik', 'SyncFingerprint' => 'fp_same', 'InlineAttachmentCount' => 0, 'HTMLBodyArticleCount' => 0, 'customer_user_registered' => true]));
        Redis::shouldReceive('setex')->with('znuny:ticket_indexes:1003', \Mockery::any(), \Mockery::any());

        $result = $service->upsertOrRefreshFromSearchResult($ticket);
        $this->assertEquals('updated_changed', $result);
    }

    public function test_upsert_or_refresh_true_unchanged()
    {
        $mockLookup = \Mockery::mock(ZnunyLookupCacheReadService::class);
        $mockLookup->shouldReceive('hasCustomerCompany')->with('agrotekhnik')->andReturn(true);
        $service = new ZnunyTicketCacheService($mockLookup);

        $ticket = [
            'TicketID' => 1004,
            'CustomerID' => 'agrotekhnik',
            'SyncFingerprint' => 'fp_same',
            'InlineAttachmentCount' => 0,
            'HTMLBodyArticleCount' => 0,
        ];
        // Exists in cache with true
        $existing = json_encode(['TicketID' => 1004, 'CustomerID' => 'agrotekhnik', 'SyncFingerprint' => 'fp_same', 'InlineAttachmentCount' => 0, 'HTMLBodyArticleCount' => 0, 'customer_user_registered' => true]);

        Redis::shouldReceive('get')->with('znuny:ticket:1004')->andReturn($existing);
        Redis::shouldReceive('get')->with('znuny:ticket_indexes:1004')->andReturn(json_encode(['znuny:index:queue:1']));

        Redis::shouldReceive('expire')->with('znuny:ticket:1004', 600)->once();
        Redis::shouldReceive('expire')->with('znuny:ticket_indexes:1004', \Mockery::any())->once();
        Redis::shouldReceive('expire')->with('znuny:index:queue:1', \Mockery::any())->once();
        Redis::shouldReceive('setex')->never();

        $result = $service->upsertOrRefreshFromSearchResult($ticket);
        $this->assertEquals('refreshed_unchanged', $result);
    }

    public function test_update_ticket_identity_maintains_reverse_index()
    {
        $mockLookup = \Mockery::mock(ZnunyLookupCacheReadService::class);
        $mockLookup->shouldReceive('hasCustomerCompany')->with('new_comp')->andReturn(true);
        $service = new ZnunyTicketCacheService($mockLookup);

        $existing = json_encode([
            'TicketID' => 2005,
            'CustomerUserID' => 'old_user',
            'CustomerID' => 'old_comp',
            'customer_user_registered' => false,
        ]);

        Redis::shouldReceive('get')->with('znuny:ticket:2005')->andReturn($existing);
        Redis::shouldReceive('ttl')->with('znuny:ticket:2005')->andReturn(300);

        Redis::shouldReceive('get')->with('znuny:ticket_indexes:2005')->andReturn(json_encode([
            'znuny:index:customer_user:old_user',
        ]));

        Redis::shouldReceive('setex')->with('znuny:ticket:2005', 300, \Mockery::any())->once();

        Redis::shouldReceive('zrem')->with('znuny:index:customer_user:old_user', 2005)->once();

        Redis::shouldReceive('zadd')->with('znuny:index:customer_user:new_user', \Mockery::any(), 2005)->once();
        Redis::shouldReceive('expire')->with('znuny:index:customer_user:new_user', \Mockery::any())->once();

        Redis::shouldReceive('setex')->with('znuny:ticket_indexes:2005', \Mockery::any(), json_encode([
            'znuny:index:customer_user:new_user',
        ]))->once();

        $service->updateTicketIdentity(2005, 'new_user', 'new_comp');
    }

    public function test_refresh_preserves_reconciled_registration_for_same_mail_sender(): void
    {
        $login = 'oleksandr.ustinov@tmm.ua';
        $ticketId = 59360;
        $key = "znuny:ticket:{$ticketId}";
        $reverseKey = "znuny:ticket_indexes:{$ticketId}";

        $lookup = \Mockery::mock(ZnunyLookupCacheReadService::class);
        $lookup->shouldReceive('hasCustomerCompany')
            ->once()
            ->with($login)
            ->andReturn(false);
        $lookup->shouldReceive('hasCustomerCompany')
            ->once()
            ->with('vamark project')
            ->andReturn(true);

        $service = new ZnunyTicketCacheService($lookup);

        $existing = [
            'TicketID' => $ticketId,
            'CustomerUserID' => $login,
            'CustomerID' => 'vamark project',
            'customer_user_registered' => true,
            'SyncFingerprint' => 'same',
            'InlineAttachmentCount' => 0,
            'HTMLBodyArticleCount' => 0,
            'StateType' => 'open',
        ];

        $incoming = [
            'TicketID' => $ticketId,
            'CustomerUserID' => $login,
            'CustomerID' => $login,
            'SyncFingerprint' => 'same',
            'InlineAttachmentCount' => 0,
            'HTMLBodyArticleCount' => 0,
            'StateType' => 'open',
        ];

        Redis::shouldReceive('get')
            ->once()
            ->with($key)
            ->andReturn(json_encode($existing));

        Redis::shouldReceive('expire')
            ->once()
            ->with($key, 600);

        Redis::shouldReceive('expire')
            ->once()
            ->with($reverseKey, 604800);

        Redis::shouldReceive('get')
            ->once()
            ->with($reverseKey)
            ->andReturn(null);

        Redis::shouldReceive('setex')->never();

        $result = $service->upsertOrRefreshFromSearchResult($incoming);

        $this->assertSame('refreshed_unchanged', $result);
    }
}
