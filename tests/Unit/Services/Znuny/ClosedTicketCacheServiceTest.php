<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use App\Services\Znuny\ClosedTicketCacheService;
use App\Services\Znuny\ZnunyCustomerUserExistenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ClosedTicketCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    private ClosedTicketCacheService $service;

    private $mockExistence;

    protected function setUp(): void
    {
        parent::setUp();
        $mockLookup = \Mockery::mock(ZnunyLookupCacheReadService::class);
        $mockLookup->shouldReceive('hasCustomerCompany')->andReturn(false)->byDefault();
        $this->mockExistence = \Mockery::mock(ZnunyCustomerUserExistenceService::class);
        $this->mockExistence->shouldReceive('checkCustomerUserExistence')->andReturn(['registered' => false, 'source' => 'missing', 'generation' => null])->byDefault();

        $this->service = new ClosedTicketCacheService($mockLookup, $this->mockExistence);

        $keys = Redis::keys('znuny:closed_ticket:*');
        if (! empty($keys)) {
            // Redis::keys returns prefixed keys if prefixing is on, but Redis::del takes unprefixed.
            // Using a simple command loop with unprefixed if we have to, but since it's testing:
            $prefix = config('database.redis.options.prefix', '');
            $unprefixed = array_map(function ($k) use ($prefix) {
                return ($prefix && str_starts_with($k, $prefix)) ? substr($k, strlen($prefix)) : $k;
            }, $keys);
            Redis::del($unprefixed);
        }
    }

    public function test_upsert_ticket_calculates_retention_correctly()
    {
        $ticket = [
            'TicketID' => 123,
            'Created' => '2023-10-01 12:00:00',
            'InlineAttachmentCount' => 2,
        ];

        $retentionDays = 180; // 30 * 6
        $expectedSeconds = 180 * 86400;

        $this->service->upsertTicket($ticket, $retentionDays);

        $this->assertEquals(
            json_encode(array_merge($ticket, ['customer_user_registered' => false])),
            Redis::get('znuny:closed_ticket:ticket:123')
        );

        $this->assertGreaterThan(0, Redis::ttl('znuny:closed_ticket:ticket:123'));
        $this->assertEquals(
            ['123'],
            Redis::zrange('znuny:closed_ticket:index:2023-10-01', 0, -1)
        );
    }

    public function test_missing_created_does_not_create_wrong_changed_based_index()
    {
        $ticket = [
            'TicketID' => 124,
            'Changed' => '2023-10-01 12:00:00',
        ];

        $this->service->upsertTicket($ticket, 180);

        $this->assertNull(Redis::get('znuny:closed_ticket:ticket:124'));
        $keys = Redis::keys('znuny:closed_ticket:index:*');
        $this->assertEmpty($keys ? $keys : []);
    }

    public function test_validate_metadata_missing_returns_false()
    {
        $result = $this->service->validateMetadata(30);
        $this->assertFalse($result['is_valid']);
        $this->assertEquals('metadata_missing', $result['reason']);
    }

    public function test_validate_metadata_incomplete_returns_false()
    {
        $this->service->setMetadata(['integrity_status' => 'incomplete']);
        $result = $this->service->validateMetadata(30);
        $this->assertFalse($result['is_valid']);
        $this->assertEquals('metadata_incomplete', $result['reason']);
    }

    public function test_validate_metadata_different_window_returns_false()
    {
        $this->service->setMetadata([
            'integrity_status' => 'complete',
            'window_days' => 60,
            'last_full_completed_at' => now()->toDateTimeString(),
            'oldest_loaded_closed_at' => date('Y-m-d H:i:s', time() - (90 * 86400)),
        ]);
        $result = $this->service->validateMetadata(30);
        $this->assertFalse($result['is_valid']);
        $this->assertEquals('metadata_window_changed', $result['reason']);
    }

    public function test_validate_metadata_valid_returns_true()
    {
        $this->service->setMetadata([
            'integrity_status' => 'complete',
            'window_days' => 30,
            'last_full_completed_at' => now()->toDateTimeString(),
            'oldest_loaded_closed_at' => date('Y-m-d H:i:s', time() - (40 * 86400)), // Older than 30 days boundary
        ]);
        $result = $this->service->validateMetadata(30);
        $this->assertTrue($result['is_valid']);
        $this->assertEquals('complete', $result['reason']);
    }

    public function test_get_recent_ticket_ids_reads_all_available_indexes()
    {
        Redis::del('znuny:closed_ticket:index:2023-10-01');
        Redis::del('znuny:closed_ticket:index:2023-09-01');

        Redis::zadd('znuny:closed_ticket:index:2023-10-01', 1, 100);
        Redis::zadd('znuny:closed_ticket:index:2023-10-01', 2, 101);

        // well beyond window
        Redis::zadd('znuny:closed_ticket:index:2023-09-01', 3, 102);
        Redis::zadd('znuny:closed_ticket:index:2023-09-01', 4, 100); // dedup

        $ids = $this->service->getRecentTicketIds();

        $this->assertCount(3, $ids);
        $this->assertContains(100, $ids);
        $this->assertContains(101, $ids);
        $this->assertContains(102, $ids);

        Redis::del('znuny:closed_ticket:index:2023-10-01');
        Redis::del('znuny:closed_ticket:index:2023-09-01');
    }

    public function test_get_recent_ticket_ids_works_when_metadata_missing()
    {
        Redis::del('znuny:closed_ticket:index:2023-10-01');
        Redis::zadd('znuny:closed_ticket:index:2023-10-01', 1, 999);

        $ids = $this->service->getRecentTicketIds();

        $this->assertCount(1, $ids);
        $this->assertContains(999, $ids);

        Redis::del('znuny:closed_ticket:index:2023-10-01');
    }

    public function test_registered_customer_is_enriched_before_cache(): void
    {
        $mockLookup = \Mockery::mock(ZnunyLookupCacheReadService::class);
        $this->mockExistence->shouldReceive('checkCustomerUserExistence')->once()->with('user125@example.com', 'agrotekhnik')->andReturn(['registered' => true, 'source' => 'prewarm', 'generation' => null]);
        $service = new ClosedTicketCacheService($mockLookup, $this->mockExistence);

        Redis::shouldReceive('get')->with('znuny:closed_ticket:ticket:125')->andReturn(null);

        $ticket = [
            'TicketID' => 125,
            'CustomerUserID' => 'user125@example.com',
            'CustomerID' => 'agrotekhnik',
            'Created' => '2023-10-01 12:00:00',
        ];
        $retentionDays = 180;
        $retentionSeconds = $retentionDays * 86400;
        $timestamp = strtotime($ticket['Created']);

        Redis::shouldReceive('setex')->once()->with(
            'znuny:closed_ticket:ticket:125',
            $retentionSeconds,
            json_encode(array_merge($ticket, ['customer_user_registered' => true]))
        );
        Redis::shouldReceive('zadd')->once()->with('znuny:closed_ticket:index:2023-10-01', $timestamp, 125);
        Redis::shouldReceive('expire')->once()->with('znuny:closed_ticket:index:2023-10-01', $retentionSeconds);
        Redis::shouldReceive('zadd')->once()->with(
            'znuny:closed_ticket:customer_user_index:user125@example.com',
            $timestamp,
            125
        );
        Redis::shouldReceive('ttl')
            ->once()
            ->with('znuny:closed_ticket:customer_user_index:user125@example.com')
            ->andReturn(-1);
        Redis::shouldReceive('expire')->once()->with(
            'znuny:closed_ticket:customer_user_index:user125@example.com',
            $retentionSeconds
        );

        $service->upsertTicket($ticket, $retentionDays);
    }

    public function test_mail_only_customer_is_enriched_before_cache(): void
    {
        $mockLookup = \Mockery::mock(ZnunyLookupCacheReadService::class);
        $this->mockExistence->shouldReceive('checkCustomerUserExistence')->once()->with('', 'oleksandr.ustinov@tmm.ua')->andReturn(['registered' => false, 'source' => 'live', 'generation' => null]);
        $service = new ClosedTicketCacheService($mockLookup, $this->mockExistence);

        Redis::shouldReceive('get')->with('znuny:closed_ticket:ticket:126')->andReturn(null);

        $ticket = [
            'TicketID' => 126,
            'CustomerID' => 'oleksandr.ustinov@tmm.ua',
            'Created' => '2023-10-01 12:00:00',
        ];
        $retentionDays = 180;
        $retentionSeconds = $retentionDays * 86400;
        $timestamp = strtotime($ticket['Created']);

        Redis::shouldReceive('setex')->once()->with(
            'znuny:closed_ticket:ticket:126',
            $retentionSeconds,
            json_encode(array_merge($ticket, ['customer_user_registered' => false]))
        );
        Redis::shouldReceive('zadd')->once()->with('znuny:closed_ticket:index:2023-10-01', $timestamp, 126);
        Redis::shouldReceive('expire')->once()->with('znuny:closed_ticket:index:2023-10-01', $retentionSeconds);

        $service->upsertTicket($ticket, $retentionDays);
    }

    public function test_upsert_ticket_removes_old_user_membership()
    {
        $mockLookup = \Mockery::mock(ZnunyLookupCacheReadService::class);
        $mockLookup->shouldReceive('hasCustomerCompany')->andReturn(false);
        $service = new ClosedTicketCacheService($mockLookup, $this->mockExistence);

        $ticket = [
            'TicketID' => 222,
            'Created' => '2023-10-01 12:00:00',
            'CustomerUserID' => 'new_user',
        ];

        // Seed old ticket
        Redis::setex('znuny:closed_ticket:ticket:222', 3600, json_encode(['TicketID' => 222, 'CustomerUserID' => 'old_user']));
        Redis::zadd('znuny:closed_ticket:customer_user_index:old_user', 1000, 222);

        $service->upsertTicket($ticket, 30);

        // old user should be removed
        $this->assertEmpty(Redis::zrange('znuny:closed_ticket:customer_user_index:old_user', 0, -1));

        // new user should be added
        $this->assertEquals(['222'], Redis::zrange('znuny:closed_ticket:customer_user_index:new_user', 0, -1));

        // TTL should be extended
        $this->assertGreaterThan(100, Redis::ttl('znuny:closed_ticket:customer_user_index:new_user'));
    }

    public function test_forget_ticket_removes_user_membership()
    {
        $mockLookup = \Mockery::mock(ZnunyLookupCacheReadService::class);
        $service = new ClosedTicketCacheService($mockLookup, $this->mockExistence);

        Redis::setex('znuny:closed_ticket:ticket:333', 3600, json_encode([
            'TicketID' => 333,
            'Created' => '2023-10-01 12:00:00',
            'CustomerUserID' => 'some_user',
        ]));
        Redis::zadd('znuny:closed_ticket:customer_user_index:some_user', 1000, 333);

        $service->forgetTicket(333);

        $this->assertNull(Redis::get('znuny:closed_ticket:ticket:333'));
        $this->assertEmpty(Redis::zrange('znuny:closed_ticket:customer_user_index:some_user', 0, -1));
    }

    public function test_bimat_missing_customer_user_preserves_identity_and_marks_unregistered(): void
    {
        $this->mockExistence->shouldReceive('checkCustomerUserExistence')
            ->once()
            ->with('ns@zagorovski.ai', 'bimat')
            ->andReturn(['registered' => false, 'source' => 'live', 'generation' => 'gen1']);

        Redis::del('znuny:closed_ticket:ticket:9100');

        $ticket = [
            'TicketID' => 9100,
            'Created' => '2023-10-01 12:00:00',
            'CustomerUserID' => 'ns@zagorovski.ai',
            'CustomerID' => 'bimat',
        ];

        $this->service->upsertTicket($ticket, 30);

        $cached = $this->service->getTicket(9100);

        $this->assertSame('ns@zagorovski.ai', $cached['CustomerUserID']);
        $this->assertSame('bimat', $cached['CustomerID']);
        $this->assertFalse($cached['customer_user_registered']);
    }

    public function test_recent_confirmed_marker_blocks_stale_closed_ticket_identity(): void
    {
        $ticketKey = 'znuny:closed_ticket:ticket:9101';
        Redis::del($ticketKey);
        Redis::del('znuny:identity_marker:9101');
        Redis::setex($ticketKey, 3600, json_encode([
            'TicketID' => 9101,
            'Created' => '2023-10-01 12:00:00',
            'CustomerUserID' => 'confirmed@example.com',
            'CustomerID' => 'confirmed-comp',
            'customer_user_registered' => true,
            'Queue' => 'old-queue',
        ]));

        Redis::setex('znuny:identity_marker:9101', 300, 1);

        $this->mockExistence->shouldNotReceive('checkCustomerUserExistence');

        $this->service->upsertTicket([
            'TicketID' => 9101,
            'Created' => '2023-10-01 12:00:00',
            'CustomerUserID' => 'stale@example.com',
            'CustomerID' => 'stale-comp',
            'Queue' => 'new-queue',
        ], 30);

        $cached = $this->service->getTicket(9101);

        $this->assertSame('confirmed@example.com', $cached['CustomerUserID']);
        $this->assertSame('confirmed-comp', $cached['CustomerID']);
        $this->assertTrue($cached['customer_user_registered']);
        $this->assertSame('new-queue', $cached['Queue']);
    }

    public function test_absent_marker_allows_external_closed_ticket_customer_id_change(): void
    {
        $ticketKey = 'znuny:closed_ticket:ticket:9102';
        Redis::del($ticketKey);
        Redis::del('znuny:identity_marker:9102');
        Redis::setex($ticketKey, 3600, json_encode([
            'TicketID' => 9102,
            'Created' => '2023-10-01 12:00:00',
            'CustomerUserID' => 'user@example.com',
            'CustomerID' => 'old-comp',
            'customer_user_registered' => true,
            'Queue' => 'old-queue',
        ]));

        $this->mockExistence->shouldReceive('checkCustomerUserExistence')
            ->once()
            ->with('user@example.com', 'external-comp')
            ->andReturn(['registered' => true, 'source' => 'live', 'generation' => 'gen1']);

        $this->service->upsertTicket([
            'TicketID' => 9102,
            'Created' => '2023-10-01 12:00:00',
            'CustomerUserID' => 'user@example.com',
            'CustomerID' => 'external-comp',
            'Queue' => 'new-queue',
        ], 30);

        $cached = $this->service->getTicket(9102);

        $this->assertSame('external-comp', $cached['CustomerID']);
        $this->assertTrue($cached['customer_user_registered']);
        $this->assertSame('new-queue', $cached['Queue']);
    }

    public function test_confirmed_closed_ticket_mirror_preserves_ttl_and_reverse_index(): void
    {
        $ticketKey = 'znuny:closed_ticket:ticket:9103';
        $oldIndex = 'znuny:closed_ticket:customer_user_index:old@example.com';
        $newIndex = 'znuny:closed_ticket:customer_user_index:new@example.com';

        Redis::del($ticketKey);
        Redis::del($oldIndex);
        Redis::del($newIndex);

        Redis::setex($ticketKey, 300, json_encode([
            'TicketID' => 9103,
            'Created' => '2023-10-01 12:00:00',
            'CustomerUserID' => 'old@example.com',
            'CustomerID' => 'old-comp',
            'customer_user_registered' => false,
        ]));
        Redis::zadd($oldIndex, 1, 9103);

        $beforeTtl = Redis::ttl($ticketKey);

        $this->service->mirrorConfirmedTicketIdentity(9103, 'new@example.com', 'new-comp');

        $afterTtl = Redis::ttl($ticketKey);
        $cached = $this->service->getTicket(9103);

        $this->assertGreaterThan(0, $afterTtl);
        $this->assertLessThanOrEqual($beforeTtl, $afterTtl);
        $this->assertSame('new@example.com', $cached['CustomerUserID']);
        $this->assertSame('new-comp', $cached['CustomerID']);
        $this->assertTrue($cached['customer_user_registered']);
        $this->assertEmpty(Redis::zrange($oldIndex, 0, -1));
        $this->assertContains('9103', Redis::zrange($newIndex, 0, -1));
    }

    public function test_confirmed_closed_ticket_mirror_does_not_create_uncached_historical_ticket(): void
    {
        $ticketKey = 'znuny:closed_ticket:ticket:9104';
        Redis::del($ticketKey);

        $this->service->mirrorConfirmedTicketIdentity(9104, 'user@example.com', 'comp');

        $this->assertNull(Redis::get($ticketKey));
    }
}
