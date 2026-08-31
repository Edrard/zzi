<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use App\Services\Znuny\ClosedTicketCacheService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ClosedTicketCacheServiceTest extends TestCase
{
    private ClosedTicketCacheService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $mockLookup = \Mockery::mock(ZnunyLookupCacheReadService::class);
        $mockLookup->shouldReceive('hasCustomerCompany')->andReturn(false)->byDefault();
        $this->service = new ClosedTicketCacheService($mockLookup);

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
        $mockLookup->shouldReceive('hasCustomerCompany')->once()->with('agrotekhnik')->andReturn(true);
        $service = new ClosedTicketCacheService($mockLookup);

        Redis::shouldReceive('get')->with('znuny:closed_ticket:ticket:125')->andReturn(null);

        $ticket = [
            'TicketID' => 125,
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

        $service->upsertTicket($ticket, $retentionDays);
    }

    public function test_mail_only_customer_is_enriched_before_cache(): void
    {
        $mockLookup = \Mockery::mock(ZnunyLookupCacheReadService::class);
        $mockLookup->shouldReceive('hasCustomerCompany')->once()->with('oleksandr.ustinov@tmm.ua')->andReturn(false);
        $service = new ClosedTicketCacheService($mockLookup);

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
        $service = new ClosedTicketCacheService($mockLookup);

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
        $service = new ClosedTicketCacheService($mockLookup);

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

    public function test_closed_refresh_preserves_reconciled_registration_for_same_mail_sender(): void
    {
        $login = 'oleksandr.ustinov@tmm.ua';
        $ticketId = 59361;
        $key = "znuny:closed_ticket:ticket:{$ticketId}";

        $lookup = \Mockery::mock(ZnunyLookupCacheReadService::class);
        $lookup->shouldReceive('hasCustomerCompany')->with($login)->andReturn(false)->byDefault();
        $lookup->shouldReceive('hasCustomerCompany')->with('vamark project')->andReturn(true)->byDefault();

        $service = new ClosedTicketCacheService($lookup);

        Redis::setex($key, 600, json_encode([
            'TicketID' => $ticketId,
            'Created' => '2026-08-31 12:00:00',
            'CustomerUserID' => $login,
            'CustomerID' => 'vamark project',
            'customer_user_registered' => true,
        ]));

        try {
            $service->upsertTicket([
                'TicketID' => $ticketId,
                'Created' => '2026-08-31 12:00:00',
                'CustomerUserID' => $login,
                'CustomerID' => $login,
            ], 1);

            $cached = $service->getTicket($ticketId);

            $this->assertIsArray($cached);
            $this->assertTrue($cached['customer_user_registered']);
            $this->assertSame('vamark project', $cached['CustomerID']);
            $this->assertSame($login, $cached['CustomerUserID']);
        } finally {
            Redis::del($key);
            Redis::del('znuny:closed_ticket:index:2026-08-31');
            Redis::del("znuny:closed_ticket:customer_user_index:{$login}");
        }
    }
}
