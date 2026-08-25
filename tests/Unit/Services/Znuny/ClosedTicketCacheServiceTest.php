<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\ClosedTicketCacheService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ClosedTicketCacheServiceTest extends TestCase
{
    private ClosedTicketCacheService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ClosedTicketCacheService;
        Redis::flushdb();
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
            json_encode($ticket),
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
}
