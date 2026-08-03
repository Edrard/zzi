<?php

namespace Tests\Feature\Znuny\Cache;

use App\Services\Znuny\Cache\PrewarmSnapshotManager;
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

    public function test_read_service_returns_cached_queues_without_api_call()
    {
        $manager = new PrewarmSnapshotManager('queues');
        $manager->refresh(function () {
            return [['id' => 1, 'name' => 'Support']];
        });

        $service = new ZnunyQueueCacheReadService();
        $queues = $service->getQueues();
        
        $this->assertCount(1, $queues);
        $this->assertEquals('Support', $queues[0]['name']);
    }

    public function test_read_service_does_not_fall_back_to_znuny_when_missing()
    {
        $service = new ZnunyQueueCacheReadService();
        $queues = $service->getQueues();
        
        $this->assertIsArray($queues);
        $this->assertEmpty($queues);
        
        $meta = $service->getMetadata();
        $this->assertEquals('missing', $meta['status']);
    }
}
