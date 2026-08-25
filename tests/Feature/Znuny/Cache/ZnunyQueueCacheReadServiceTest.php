<?php

namespace Tests\Feature\Znuny\Cache;

use App\Services\Znuny\Cache\ZnunyQueueCacheReadService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ZnunyQueueCacheReadServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
    }

    public function test_missing_snapshot_returns_explicit_empty_array()
    {
        $service = new ZnunyQueueCacheReadService();
        $this->assertNull($service->getSnapshot());
        $this->assertEquals([], $service->getQueues());
    }

    public function test_successful_read_retrieves_normalized_properties()
    {
        Cache::put('znuny_prewarm_queues_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', [['id' => 1, 'name' => 'Q']], now()->addMinutes(10));

        $service = new ZnunyQueueCacheReadService();
        $snapshot = $service->getSnapshot();

        $this->assertEquals('gen_1', $snapshot['generation']);
        $this->assertEquals([['id' => 1, 'name' => 'Q']], $snapshot['payload']);
        $this->assertEquals('ready', $snapshot['metadata']['status']);

        $this->assertEquals([['id' => 1, 'name' => 'Q']], $service->getQueues());
    }

    public function test_expired_or_missing_payload_with_remaining_metadata_returns_empty()
    {
        Cache::put('znuny_prewarm_queues_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        // Payload absent

        $service = new ZnunyQueueCacheReadService();
        $this->assertNull($service->getSnapshot());
        $this->assertEquals([], $service->getQueues());
    }

    public function test_scalar_payload_returns_empty()
    {
        Cache::put('znuny_prewarm_queues_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', 'scalar_payload', now()->addMinutes(10));

        $service = new ZnunyQueueCacheReadService();
        $this->assertNull($service->getSnapshot());
        $this->assertEquals([], $service->getQueues());
    }

    public function test_cache_only_behavior_remains_and_does_not_call_http()
    {
        \Illuminate\Support\Facades\Http::fake();

        Cache::put('znuny_prewarm_queues_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', [['id' => 1, 'name' => 'Q']], now()->addMinutes(10));

        $service = new ZnunyQueueCacheReadService();
        $this->assertEquals([['id' => 1, 'name' => 'Q']], $service->getQueues());

        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_metadata_exposure_remains()
    {
        $service = new ZnunyQueueCacheReadService();
        $meta = $service->getMetadata();

        $this->assertEquals('queues', $meta['dataset_name']);
        $this->assertEquals('missing', $meta['status']);
    }
}
