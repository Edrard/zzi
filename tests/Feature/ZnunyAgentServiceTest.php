<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Znuny\ZnunyAgentService;
use App\Services\Znuny\ZnunyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
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
}
