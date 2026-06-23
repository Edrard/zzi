<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\Setting;
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
        Setting::updateOrCreate(['key' => 'znuny_ticket_cache_enabled'], ['value' => 'true']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_cache_ttl_seconds'], ['value' => '900']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_cache_closed_ttl_seconds'], ['value' => '86400']);

        $this->service = new ZnunyTicketCacheService;
    }

    public function test_it_does_not_cache_if_disabled(): void
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_cache_enabled'], ['value' => 'false']);

        Redis::shouldReceive('setex')->never();

        $this->service->upsertTicket(['TicketID' => 123]);
    }

    public function test_it_caches_ticket_payload_with_default_ttl(): void
    {
        $ticket = [
            'TicketID' => 101,
            'StateType' => 'open',
            'QueueID' => 5,
        ];

        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket:101', 900, json_encode($ticket));

        // And reverse index
        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket_indexes:101', \Mockery::any(), \Mockery::any());

        Redis::shouldReceive('zadd')->times(2); // state type and queue
        Redis::shouldReceive('expire')->times(2);

        $this->service->upsertTicket($ticket);
    }

    public function test_it_caches_closed_ticket_with_closed_ttl(): void
    {
        $ticket = [
            'TicketID' => 102,
            'StateType' => 'closed',
        ];

        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket:102', 86400, json_encode($ticket));

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
        $ticket = ['TicketID' => 200, 'Title' => 'Test'];

        Redis::shouldReceive('get')
            ->with('znuny:ticket:200')
            ->andReturn(json_encode($ticket));

        $result = $this->service->getTicket(200);

        $this->assertEquals($ticket, $result);
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
            ->with('znuny:ticket:400', 86400, json_encode($ticket));

        // And reverse index
        Redis::shouldReceive('setex')
            ->once()
            ->with('znuny:ticket_indexes:400', \Mockery::any(), \Mockery::any());

        Redis::shouldReceive('zadd')->times(1);
        Redis::shouldReceive('expire')->times(1);

        $this->service->markClosedWithShortTtl($ticket);
    }
}
