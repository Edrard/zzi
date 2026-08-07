<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Znuny\Cache\ZnunyAgentCacheReadService;
use App\Services\Znuny\ZnunyAgentService;
use App\Services\Znuny\ZnunyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ZnunyAgentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->bind(ZnunyClient::class, fn () => throw new \Exception('ZnunyClient dependency is forbidden in this phase'));
    }

    public function test_get_agents_returns_reader_payload_exactly()
    {
        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgents')->once()->andReturn([
                ['id' => 1, 'login' => 'agent1', 'valid_id' => 1],
            ]);
        });

        $service = app(ZnunyAgentService::class);
        $agents = $service->getAgents();

        $this->assertCount(1, $agents);
        $this->assertEquals('agent1', $agents[0]['login']);
    }

    public function test_repeated_get_agents_calls_read_through_reader_and_do_not_use_caching()
    {
        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgents')->twice()->andReturn([
                ['id' => 1, 'login' => 'agent1', 'valid_id' => 1],
            ]);
        });

        $service = app(ZnunyAgentService::class);
        $service->getAgents();
        $service->getAgents();
    }

    public function test_reader_miss_returns_empty_array_with_no_live_fallback()
    {
        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgents')->once()->andReturn([]);
        });

        $service = app(ZnunyAgentService::class);
        $agents = $service->getAgents();

        $this->assertEquals([], $agents);
    }

    public function test_reader_exception_preserves_last_error()
    {
        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgents')->once()->andThrow(new \Exception('Reader error message'));
        });

        $service = app(ZnunyAgentService::class);
        $service->getAgents();

        $this->assertEquals('Reader error message', $service->lastError());
    }

    public function test_reader_exception_returns_empty_array_when_fail_silently_is_true()
    {
        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgents')->once()->andThrow(new \Exception('Reader error'));
        });

        $service = app(ZnunyAgentService::class);
        $agents = $service->getAgents(failSilently: true);

        $this->assertEquals([], $agents);
    }

    public function test_reader_exception_is_rethrown_when_fail_silently_is_false()
    {
        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgents')->once()->andThrow(new \Exception('Reader error'));
        });

        $service = app(ZnunyAgentService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Reader error');

        $service->getAgents(failSilently: false);
    }

    public function test_force_refresh_does_not_mutate_reader_or_use_api()
    {
        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgents')->once()->andReturn([
                ['id' => 1, 'login' => 'agent1', 'valid_id' => 1],
            ]);
        });

        $service = app(ZnunyAgentService::class);
        $agents = $service->getAgents(failSilently: true, forceRefresh: true);

        $this->assertCount(1, $agents);
    }

    public function test_get_selectable_agents_preserves_valid_agent_and_excluded_login_filtering()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_agent_exclude_logins'],
            ['type' => 'string', 'value' => 'agent2']
        );

        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgents')->once()->andReturn([
                ['id' => 1, 'login' => 'agent1', 'valid_id' => 1],
                ['id' => 2, 'login' => 'agent2', 'valid_id' => 1], // Excluded via settings
                ['id' => 3, 'login' => 'agent3', 'valid_id' => 0], // Invalid
                ['id' => 4, 'login' => 'agent4', 'valid_id' => 1],
            ]);
        });

        $service = app(ZnunyAgentService::class);
        $agents = $service->getSelectableAgents();

        $this->assertCount(2, $agents);
        $this->assertEquals('agent1', $agents[0]['login']);
        $this->assertEquals('agent4', $agents[1]['login']);
    }

    public function test_agent_labels_and_get_agent_name_map_remain_compatible()
    {
        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgents')->once()->andReturn([
                ['id' => 1, 'login' => 'agent1', 'name' => 'Agent One', 'valid_id' => 1],
                ['id' => 2, 'login' => 'agent2', 'valid_id' => 1], // Falls back to login
                ['id' => 3, 'valid_id' => 1], // Falls back to Agent ID
            ]);
        });

        $service = app(ZnunyAgentService::class);
        $map = $service->getAgentNameMap();

        $this->assertCount(3, $map);
        $this->assertEquals('Agent One', $map[1]);
        $this->assertEquals('agent2', $map[2]);
        $this->assertEquals('Agent 3', $map[3]);
    }

    public function test_queue_specific_agent_retrieval_uses_reader()
    {
        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgentIdsForQueue')->with(99)->once()->andReturn([1, 3]);
            $mock->shouldReceive('getAgents')->once()->andReturn([
                ['id' => 1, 'login' => 'agent1', 'valid_id' => 1],
                ['id' => 2, 'login' => 'agent2', 'valid_id' => 1],
                ['id' => 3, 'login' => 'agent3', 'valid_id' => 1],
            ]);
        });

        $service = app(ZnunyAgentService::class);
        $agents = $service->getSelectableAssignableAgentsForQueue(99);

        $this->assertCount(2, $agents);
        $this->assertEquals('agent1', $agents[0]['login']);
        $this->assertEquals('agent3', $agents[1]['login']);
    }

    public function test_queue_specific_retrieval_returns_empty_for_unknown_queue()
    {
        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgentIdsForQueue')->with(999)->once()->andReturn([]);
            $mock->shouldReceive('getAgents')->once()->andReturn([
                ['id' => 1, 'login' => 'agent1', 'valid_id' => 1],
            ]);
        });

        $service = app(ZnunyAgentService::class);
        $agents = $service->getSelectableAssignableAgentsForQueue(999);

        $this->assertEquals([], $agents);
    }
}
