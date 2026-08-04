<?php

namespace Tests\Feature\Znuny\Cache;

use App\Services\Znuny\Cache\ZnunyAgentCacheReadService;
use App\Services\Znuny\Cache\ZnunyQueueCacheReadService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Mockery;

class ZnunyAgentCacheReadServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
    }

    public function test_missing_snapshot_returns_explicit_empty_arrays()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'missing', 'payload' => []]);

        $service = new ZnunyAgentCacheReadService($queueService);
        $this->assertEquals([], $service->getAgents());
        $this->assertEquals([], $service->getQueueIdsForAgent(1));
        $this->assertEquals([], $service->getAgentIdsForQueue(1));
    }

    public function test_cache_only_behavior_remains()
    {
        Cache::forever('znuny_prewarm_agents_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ]);
        Cache::forever('gen_1', [
            'queue_generation' => 'queue_gen_1',
            'agents' => [['id' => 1, 'login' => 'test', 'label' => 'Test']],
            'agent_to_queues' => [1 => [10, 20]],
            'queue_to_agents' => [10 => [1], 20 => [1]],
        ]);

        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => []]);

        $service = new ZnunyAgentCacheReadService($queueService);
        $this->assertCount(1, $service->getAgents());
        $this->assertEquals([10, 20], $service->getQueueIdsForAgent(1));
        $this->assertEquals([1], $service->getAgentIdsForQueue(10));
    }

    public function test_unknown_ids_return_explicit_empty_arrays()
    {
        Cache::forever('znuny_prewarm_agents_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ]);
        Cache::forever('gen_1', [
            'queue_generation' => 'queue_gen_1',
            'agents' => [],
            'agent_to_queues' => [],
            'queue_to_agents' => [],
        ]);

        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => []]);

        $service = new ZnunyAgentCacheReadService($queueService);
        $this->assertEquals([], $service->getQueueIdsForAgent(999));
        $this->assertEquals([], $service->getAgentIdsForQueue(999));
    }

    public function test_corrupted_payload_returns_explicit_empty_arrays()
    {
        Cache::forever('znuny_prewarm_agents_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ]);
        Cache::forever('gen_1', [
            'queue_generation' => 'queue_gen_1',
            'agents' => 'corrupted_string',
            'agent_to_queues' => 123,
            'queue_to_agents' => false,
        ]);

        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => []]);

        $service = new ZnunyAgentCacheReadService($queueService);
        $this->assertEquals([], $service->getAgents());
        $this->assertEquals([], $service->getAgentToQueuesMap());
        $this->assertEquals([], $service->getQueueToAgentsMap());
        $this->assertEquals([], $service->getQueueIdsForAgent(1));
        $this->assertEquals([], $service->getAgentIdsForQueue(1));
    }

    public function test_mismatched_queue_generation_returns_empty_arrays()
    {
        Cache::forever('znuny_prewarm_agents_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ]);
        Cache::forever('gen_1', [
            'queue_generation' => 'queue_gen_1',
            'agents' => [['id' => 1, 'login' => 'test', 'label' => 'Test']],
        ]);

        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_2', 'payload' => []]);

        $service = new ZnunyAgentCacheReadService($queueService);
        $this->assertEquals([], $service->getAgents());
    }

    public function test_matching_generations_return_exact_coherent_shape()
    {
        Cache::forever('znuny_prewarm_agents_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ]);
        Cache::forever('gen_1', [
            'queue_generation' => '  queue_gen_1  ',
            'agents' => [['id' => 1]],
            'agent_to_queues' => [1 => [10]],
            'queue_to_agents' => [10 => [1]],
        ]);

        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn([
            'generation' => '  queue_gen_1  ',
            'payload' => [['id' => 10]]
        ]);

        $service = new ZnunyAgentCacheReadService($queueService);
        $snapshot = $service->getSnapshot();

        $this->assertEquals('gen_1', $snapshot['generation']);
        $this->assertEquals('queue_gen_1', $snapshot['queue_generation']);
        $this->assertEquals([['id' => 1]], $snapshot['agents']);
        $this->assertEquals([1 => [10]], $snapshot['agent_to_queues']);
        $this->assertEquals([10 => [1]], $snapshot['queue_to_agents']);
        $this->assertEquals('ready', $snapshot['metadata']['status']);
    }

    public function test_integer_queue_generation_returns_null()
    {
        Cache::forever('znuny_prewarm_agents_meta', ['active_generation' => 'gen_1', 'status' => 'ready']);
        Cache::forever('gen_1', ['queue_generation' => 'queue_gen_1', 'agents' => [], 'agent_to_queues' => [], 'queue_to_agents' => []]);

        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 123, 'payload' => []]);

        $service = new ZnunyAgentCacheReadService($queueService);
        $this->assertNull($service->getSnapshot());
        $this->assertEquals([], $service->getAgents());
    }

    public function test_blank_queue_generation_returns_null()
    {
        Cache::forever('znuny_prewarm_agents_meta', ['active_generation' => 'gen_1', 'status' => 'ready']);
        Cache::forever('gen_1', ['queue_generation' => 'queue_gen_1', 'agents' => [], 'agent_to_queues' => [], 'queue_to_agents' => []]);

        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => '   ', 'payload' => []]);

        $service = new ZnunyAgentCacheReadService($queueService);
        $this->assertNull($service->getSnapshot());
    }

    public function test_missing_or_non_array_queue_payload_returns_null()
    {
        Cache::forever('znuny_prewarm_agents_meta', ['active_generation' => 'gen_1', 'status' => 'ready']);
        Cache::forever('gen_1', ['queue_generation' => 'queue_gen_1', 'agents' => [], 'agent_to_queues' => [], 'queue_to_agents' => []]);

        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => 'scalar']);

        $service = new ZnunyAgentCacheReadService($queueService);
        $this->assertNull($service->getSnapshot());
    }

    public function test_missing_integer_or_blank_agent_queue_generation_returns_null()
    {
        Cache::forever('znuny_prewarm_agents_meta', ['active_generation' => 'gen_1', 'status' => 'ready']);

        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => []]);
        $service = new ZnunyAgentCacheReadService($queueService);

        // Missing
        Cache::forever('gen_1', ['agents' => [], 'agent_to_queues' => [], 'queue_to_agents' => []]);
        $this->assertNull($service->getSnapshot());

        // Integer
        Cache::forever('gen_1', ['queue_generation' => 123, 'agents' => [], 'agent_to_queues' => [], 'queue_to_agents' => []]);
        $this->assertNull($service->getSnapshot());

        // Blank
        Cache::forever('gen_1', ['queue_generation' => '   ', 'agents' => [], 'agent_to_queues' => [], 'queue_to_agents' => []]);
        $this->assertNull($service->getSnapshot());
    }

    public function test_expired_missing_queue_snapshot_returns_null()
    {
        Cache::forever('znuny_prewarm_agents_meta', ['active_generation' => 'gen_1', 'status' => 'ready']);
        Cache::forever('gen_1', ['queue_generation' => 'queue_gen_1', 'agents' => [], 'agent_to_queues' => [], 'queue_to_agents' => []]);

        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(null);

        $service = new ZnunyAgentCacheReadService($queueService);
        $this->assertNull($service->getSnapshot());
    }

    public function test_expired_missing_agent_payload_returns_null()
    {
        Cache::forever('znuny_prewarm_agents_meta', ['active_generation' => 'gen_1', 'status' => 'ready']);
        // gen_1 payload is explicitly missing/expired

        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => []]);

        $service = new ZnunyAgentCacheReadService($queueService);
        $this->assertNull($service->getSnapshot());
    }
}
