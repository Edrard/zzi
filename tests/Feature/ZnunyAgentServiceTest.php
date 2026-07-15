<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Znuny\ZnunyAgentService;
use App\Services\Znuny\ZnunyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ZnunyAgentServiceTest extends TestCase
{
    use RefreshDatabase;

    private ZnunyAgentService $service;

    private MockInterface $clientMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientMock = $this->mock(ZnunyClient::class);
        $this->service = app(ZnunyAgentService::class);
    }

    public function test_selectable_agents_parsing_trimming_and_case_insensitive_exclusion()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_agent_exclude_logins'],
            ['type' => 'string', 'value' => " \n AgEnt1 \n\nagent2\n  "]
        );

        $this->clientMock->shouldReceive('getAgents')->once()->andReturn([
            ['id' => 1, 'login' => 'agent1', 'valid_id' => 1],
            ['id' => 2, 'login' => 'agent2', 'valid_id' => 1],
            ['id' => 3, 'login' => 'agent3', 'valid_id' => 1],
        ]);

        $agents = $this->service->getSelectableAgents();

        $this->assertCount(1, $agents);
        $this->assertEquals('agent3', $agents[0]['login']);
    }

    public function test_missing_empty_setting_returns_all_agents()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_agent_exclude_logins'],
            ['type' => 'string', 'value' => '']
        );

        $this->clientMock->shouldReceive('getAgents')->once()->andReturn([
            ['id' => 1, 'login' => 'agent1', 'valid_id' => 1],
            ['id' => 2, 'login' => 'agent2', 'valid_id' => 1],
        ]);

        $agents = $this->service->getSelectableAgents();

        $this->assertCount(2, $agents);
        $this->assertEquals('agent1', $agents[0]['login']);
        $this->assertEquals('agent2', $agents[1]['login']);
    }

    public function test_assignable_agents_are_filtered()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_agent_exclude_logins'],
            ['type' => 'string', 'value' => 'agent2']
        );

        $this->clientMock->shouldReceive('getQueueAssignableAgents')->with(99)->once()->andReturn([
            ['id' => 1, 'login' => 'agent1'],
            ['id' => 2, 'login' => 'agent2'],
            ['id' => 3, 'login' => 'agent3'],
        ]);

        $agents = $this->service->getSelectableAssignableAgentsForQueue(99);

        $this->assertCount(2, $agents);
        $this->assertEquals('agent1', $agents[0]['login']);
        $this->assertEquals('agent3', $agents[1]['login']);
    }

    public function test_agent_cache_ttl_zero_bypasses_cache_and_repeated_calls_hit_api(): void
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_agent_cache_ttl_minutes'],
            ['type' => 'integer', 'value' => 0]
        );

        $this->clientMock->shouldReceive('getAgents')
            ->twice()
            ->andReturn([['id' => 1, 'login' => 'agent1', 'valid_id' => 1]]);

        $this->service->getAgents();
        $this->service->getAgents();

        $this->assertFalse(Cache::has('znuny_active_agents'));
    }

    public function test_agent_cache_ttl_positive_uses_cache(): void
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_agent_cache_ttl_minutes'],
            ['type' => 'integer', 'value' => 10]
        );

        $this->clientMock->shouldReceive('getAgents')
            ->once()
            ->andReturn([['id' => 1, 'login' => 'agent1', 'valid_id' => 1]]);

        $this->service->getAgents();
        $this->service->getAgents();

        $this->assertTrue(Cache::has('znuny_active_agents'));
    }

    public function test_force_refresh_with_ttl_zero_does_not_create_persistent_cache_entry(): void
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_agent_cache_ttl_minutes'],
            ['type' => 'integer', 'value' => 0]
        );

        Cache::put('znuny_active_agents', [['id' => 999, 'login' => 'stale']], 10);

        $this->clientMock->shouldReceive('getAgents')
            ->once()
            ->andReturn([['id' => 1, 'login' => 'fresh', 'valid_id' => 1]]);

        $result = $this->service->getAgents(true, true);

        $this->assertEquals('fresh', $result[0]['login']);
        $this->assertFalse(Cache::has('znuny_active_agents'));
    }

    public function test_force_refresh_with_positive_ttl_invalidates_cache_and_reloads(): void
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_agent_cache_ttl_minutes'],
            ['type' => 'integer', 'value' => 10]
        );

        $this->clientMock->shouldReceive('getAgents')
            ->twice()
            ->andReturn([['id' => 1, 'login' => 'agent1', 'valid_id' => 1]]);

        $this->service->getAgents();
        $this->assertTrue(Cache::has('znuny_active_agents'));

        $this->service->getAgents(true, true);
    }

    public function test_agent_cache_expiration(): void
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_agent_cache_ttl_minutes'],
            ['type' => 'integer', 'value' => 10]
        );

        $this->clientMock->shouldReceive('getAgents')
            ->times(2)
            ->andReturn(
                [['id' => 1, 'login' => 'datasetA', 'valid_id' => 1]],
                [['id' => 2, 'login' => 'datasetB', 'valid_id' => 1]]
            );

        $result1 = $this->service->getAgents();
        $this->assertEquals('datasetA', $result1[0]['login']);

        $this->travel(9)->minutes();

        $result2 = $this->service->getAgents();
        $this->assertEquals('datasetA', $result2[0]['login']);

        $this->travel(2)->minutes(); // total 11 minutes

        $result3 = $this->service->getAgents();
        $this->assertEquals('datasetB', $result3[0]['login']);

        $this->travelBack();
    }

    public static function agentFallbackDataProvider(): array
    {
        return [
            'missing' => [null, 'string'],
            'unreadable string' => ['not-an-integer', 'string'],
            'negative' => [-5, 'integer'],
        ];
    }

    #[DataProvider('agentFallbackDataProvider')]
    public function test_agent_cache_ttl_fallback_for_invalid_values($value, $type): void
    {
        if ($value !== null) {
            Setting::updateOrCreate(
                ['key' => 'znuny_agent_cache_ttl_minutes'],
                ['type' => $type, 'value' => $value]
            );
        } else {
            Setting::where('key', 'znuny_agent_cache_ttl_minutes')->delete();
        }

        $this->clientMock->shouldReceive('getAgents')
            ->once()
            ->andReturn([['id' => 1, 'login' => 'agent1', 'valid_id' => 1]]);

        $this->service->getAgents();
        $this->service->getAgents();

        $this->assertTrue(Cache::has('znuny_active_agents'));
    }
}
