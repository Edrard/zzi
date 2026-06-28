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
        Redis::shouldReceive('setex')->byDefault();
        Redis::shouldReceive('zadd')->byDefault();
        Redis::shouldReceive('expire')->byDefault();
        Redis::shouldReceive('get')->andReturnNull()->byDefault();
        Redis::shouldReceive('set')->byDefault();
    }

    public function test_upsert_ticket_calculates_retention_correctly()
    {
        $ticket = [
            'TicketID' => 123,
            'Changed' => '2023-10-01 12:00:00',
        ];

        $retentionDays = 180; // 30 * 6
        $expectedSeconds = 180 * 86400;

        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:closed_ticket:ticket:123', $expectedSeconds, json_encode($ticket));

        Redis::shouldReceive('zadd')
            ->once()
            ->with('znuny:closed_ticket:index:2023-10-01', strtotime('2023-10-01 12:00:00'), 123);

        Redis::shouldReceive('expire')
            ->once()
            ->with('znuny:closed_ticket:index:2023-10-01', $expectedSeconds);

        $this->service->upsertTicket($ticket, $retentionDays);
    }

    public function test_validate_metadata_missing_returns_false()
    {
        Redis::shouldReceive('get')->with('znuny:closed_ticket:sync:metadata')->andReturnNull();
        $result = $this->service->validateMetadata(30);
        $this->assertFalse($result['is_valid']);
        $this->assertEquals('metadata_missing', $result['reason']);
    }

    public function test_validate_metadata_incomplete_returns_false()
    {
        $metadata = json_encode(['integrity_status' => 'incomplete']);
        Redis::shouldReceive('get')->with('znuny:closed_ticket:sync:metadata')->andReturn($metadata);
        $result = $this->service->validateMetadata(30);
        $this->assertFalse($result['is_valid']);
        $this->assertEquals('metadata_incomplete', $result['reason']);
    }

    public function test_validate_metadata_different_window_returns_false()
    {
        $metadata = json_encode([
            'integrity_status' => 'complete',
            'window_days' => 60,
            'last_full_completed_at' => now()->toDateTimeString(),
            'oldest_loaded_closed_at' => date('Y-m-d H:i:s', time() - (90 * 86400)),
        ]);
        Redis::shouldReceive('get')->with('znuny:closed_ticket:sync:metadata')->andReturn($metadata);
        $result = $this->service->validateMetadata(30);
        $this->assertFalse($result['is_valid']);
        $this->assertEquals('metadata_window_changed', $result['reason']);
    }

    public function test_validate_metadata_valid_returns_true()
    {
        $metadata = json_encode([
            'integrity_status' => 'complete',
            'window_days' => 30,
            'last_full_completed_at' => now()->toDateTimeString(),
            'oldest_loaded_closed_at' => date('Y-m-d H:i:s', time() - (40 * 86400)), // Older than 30 days boundary
        ]);
        Redis::shouldReceive('get')->with('znuny:closed_ticket:sync:metadata')->andReturn($metadata);
        $result = $this->service->validateMetadata(30);
        $this->assertTrue($result['is_valid']);
        $this->assertEquals('complete', $result['reason']);
    }
}
