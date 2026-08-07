<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\Cache\ZnunyAgentCacheReadService;
use App\Services\Znuny\Cache\ZnunyQueueCacheReadService;
use App\Services\Znuny\ZnunyAssignmentDependencyService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyUiFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ZnunyAssignmentDependencyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bind a pass-through ZnunyUiFilterService so that assignment tests are deterministic
        // and do not fail if the real database/settings exclude generic test logins.
        $this->mock(ZnunyUiFilterService::class, function (MockInterface $mock) {
            $mock->shouldReceive('filterQueuesForUi')->andReturnUsing(fn ($q) => $q)->byDefault();
            $mock->shouldReceive('filterAgentsForUi')->andReturnUsing(fn ($a) => $a)->byDefault();
            $mock->shouldReceive('filterOwnerOptionsForUi')->andReturnUsing(fn ($o, $ctx = []) => $o)->byDefault();
            $mock->shouldReceive('isAgentLoginExcluded')->andReturn(false)->byDefault();
            $mock->shouldReceive('isQueueExcluded')->andReturn(false)->byDefault();
        });
    }

    private function getBaseQueues()
    {
        return [
            ['id' => 1, 'name' => 'Q1', 'label' => 'Queue 1', 'valid_id' => 1],
            ['id' => 2, 'name' => 'Q2', 'label' => 'Queue 2', 'valid_id' => 1],
            ['id' => 3, 'name' => 'Q3', 'label' => 'Queue 3', 'valid_id' => 0], // invalid
        ];
    }

    private function getBaseAgents()
    {
        return [
            ['id' => 10, 'login' => 'agent1', 'label' => 'Agent 1', 'valid_id' => 1],
            ['id' => 20, 'login' => 'agent2', 'label' => 'Agent 2', 'valid_id' => 1],
            ['id' => 30, 'login' => 'agent3', 'label' => 'Agent 3', 'valid_id' => 0], // invalid
        ];
    }

    public function test_queue_to_assignable_agent_list()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn($this->getBaseQueues());
        });

        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgentIdsForQueue')->with(1)->andReturn([10]);
            $mock->shouldReceive('getAgents')->andReturn($this->getBaseAgents());
        });

        $service = app(ZnunyAssignmentDependencyService::class);
        $agents = $service->getAssignableAgentsForQueue('Q1');

        $this->assertCount(1, $agents);
        $this->assertEquals('agent1', $agents[0]['login']);
    }

    public function test_queue_to_owner_id_options()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn($this->getBaseQueues());
        });

        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgentIdsForQueue')->with(1)->andReturn([10, 20]);
            $mock->shouldReceive('getAgents')->andReturn($this->getBaseAgents());
        });

        $service = app(ZnunyAssignmentDependencyService::class);
        $options = $service->getOwnerOptionsForQueue('Q1');

        $this->assertCount(2, $options);
        $this->assertEquals('Agent 1', $options[10]);
        $this->assertEquals('Agent 2', $options[20]);
    }

    public function test_queue_to_owner_login_options()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn($this->getBaseQueues());
        });

        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgentIdsForQueue')->with(2)->andReturn([20]);
            $mock->shouldReceive('getAgents')->andReturn($this->getBaseAgents());
        });

        $service = app(ZnunyAssignmentDependencyService::class);
        $options = $service->getOwnerLoginOptionsForQueue('Q2');

        $this->assertCount(1, $options);
        $this->assertEquals('Agent 2', $options['agent2']);
    }

    public function test_owner_id_to_queue_options()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn($this->getBaseQueues());
        });

        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueueIdsForAgent')->with(10)->andReturn([1, 2]);
            $mock->shouldReceive('getAgents')->andReturn($this->getBaseAgents());
        });

        $service = app(ZnunyAssignmentDependencyService::class);
        $options = $service->getQueueOptionsForOwnerId('10');

        $this->assertCount(2, $options);
        $this->assertEquals('Queue 1', $options['Q1']);
        $this->assertEquals('Queue 2', $options['Q2']);
    }

    public function test_owner_login_to_queue_options()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn($this->getBaseQueues());
        });

        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueueIdsForAgent')->with(20)->andReturn([2]);
            $mock->shouldReceive('getAgents')->andReturn($this->getBaseAgents());
        });

        $service = app(ZnunyAssignmentDependencyService::class);
        $options = $service->getQueueOptionsForOwnerLogin('agent2');

        $this->assertCount(1, $options);
        $this->assertEquals('Queue 2', $options['Q2']);
    }

    public function test_known_owner_login_sorting()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn([
                ['id' => 1, 'name' => 'Q1', 'label' => 'queue 10', 'valid_id' => 1],
                ['id' => 2, 'name' => 'Q2', 'label' => 'Queue 2', 'valid_id' => 1],
                ['id' => 3, 'name' => 'Q3', 'label' => 'queue 1', 'valid_id' => 1],
                ['id' => 4, 'name' => 'Q4', 'label' => 'QUEUE 100', 'valid_id' => 1],
                ['id' => 5, 'name' => 'Q5', 'label' => 'queue 2', 'valid_id' => 1],
            ]);
        });

        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueueIdsForAgent')->with(20)->andReturn([1, 2, 3, 4, 5]); // deliberately unsorted IDs
            $mock->shouldReceive('getAgents')->andReturn($this->getBaseAgents()); // agent2 is ID 20
        });

        $service = app(ZnunyAssignmentDependencyService::class);
        $options = $service->getQueueOptionsForOwnerLogin('agent2');

        $expected = [
            'Q3' => 'queue 1',
            'Q2' => 'Queue 2',
            'Q5' => 'queue 2',
            'Q1' => 'queue 10',
            'Q4' => 'QUEUE 100',
        ];
        $this->assertSame($expected, $options);
    }

    public function test_empty_owner_login_sorting()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn([
                ['id' => 1, 'name' => 'Q1', 'label' => 'queue 10', 'valid_id' => 1],
                ['id' => 2, 'name' => 'Q2', 'label' => 'Queue 2', 'valid_id' => 1],
                ['id' => 3, 'name' => 'Q3', 'label' => 'queue 1', 'valid_id' => 1],
                ['id' => 4, 'name' => 'Q4', 'label' => 'QUEUE 100', 'valid_id' => 1],
                ['id' => 5, 'name' => 'Q5', 'label' => 'queue 2', 'valid_id' => 1],
            ]);
        });

        $service = app(ZnunyAssignmentDependencyService::class);
        $options = $service->getQueueOptionsForOwnerLogin('');

        $expected = [
            'Q3' => 'queue 1',
            'Q2' => 'Queue 2',
            'Q5' => 'queue 2',
            'Q1' => 'queue 10',
            'Q4' => 'QUEUE 100',
        ];
        $this->assertSame($expected, $options);
    }

    public function test_exact_equal_label_queue_key_tie_break()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn([
                ['id' => 1, 'name' => 'QZ', 'label' => 'Same', 'valid_id' => 1],
                ['id' => 2, 'name' => 'QA', 'label' => 'Same', 'valid_id' => 1],
            ]);
        });

        $service = app(ZnunyAssignmentDependencyService::class);
        $options = $service->getQueueOptionsForOwnerLogin('');

        $expected = [
            'QA' => 'Same',
            'QZ' => 'Same',
        ];
        $this->assertSame($expected, $options);
    }

    public function test_owner_id_remains_unsorted()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn([
                ['id' => 1, 'name' => 'Q1', 'label' => 'Z Queue', 'valid_id' => 1],
                ['id' => 2, 'name' => 'Q2', 'label' => 'A Queue', 'valid_id' => 1],
                ['id' => 3, 'name' => 'Q3', 'label' => 'M Queue', 'valid_id' => 1],
            ]);
        });

        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueueIdsForAgent')->with(10)->andReturn([3, 1, 2]); // unsorted queue-ID relation
            $mock->shouldReceive('getAgents')->andReturn($this->getBaseAgents());
        });

        $service = app(ZnunyAssignmentDependencyService::class);
        $options = $service->getQueueOptionsForOwnerId('10');

        $expected = [
            'Q3' => 'M Queue',
            'Q1' => 'Z Queue',
            'Q2' => 'A Queue',
        ];
        $this->assertSame($expected, $options); // exact relation order
    }

    public function test_ui_exclusion_filtering_remains_applied()
    {
        $this->mock(ZnunyUiFilterService::class, function (MockInterface $mock) {
            $mock->shouldReceive('filterQueuesForUi')->andReturn(['Q1' => 'Queue 1']);
            $mock->shouldReceive('filterOwnerOptionsForUi')->andReturn([10 => 'Agent 1']);
            $mock->shouldReceive('isAgentLoginExcluded')->andReturn(false);
            $mock->shouldReceive('filterAgentsForUi')->andReturn([['id' => 10, 'login' => 'agent1', 'label' => 'Agent 1']]);
        });

        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn($this->getBaseQueues());
        });

        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgentIdsForQueue')->andReturn([10, 20]);
            $mock->shouldReceive('getQueueIdsForAgent')->andReturn([1, 2]);
            $mock->shouldReceive('getAgents')->andReturn($this->getBaseAgents());
        });

        $service = app(ZnunyAssignmentDependencyService::class);
        $qOptions = $service->getQueueOptionsForOwnerId('10');
        $this->assertCount(1, $qOptions);

        $oOptions = $service->getOwnerOptionsForQueue('Q1');
        $this->assertCount(1, $oOptions);
    }

    public function test_permissive_empty_queue_behavior_remains_all_selectable_agents()
    {
        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgents')->andReturn($this->getBaseAgents());
        });

        $service = app(ZnunyAssignmentDependencyService::class);
        $agents = $service->getAssignableAgentsForQueue('');

        // Agent 1 and Agent 2 are valid, Agent 3 is invalid
        $this->assertCount(2, $agents);
    }

    public function test_permissive_unknown_queue_behavior_remains_all_selectable_agents()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn($this->getBaseQueues());
        });

        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgents')->andReturn($this->getBaseAgents());
        });

        $service = app(ZnunyAssignmentDependencyService::class);
        $agents = $service->getAssignableAgentsForQueue('UnknownQueue');

        $this->assertCount(2, $agents);
    }

    public function test_strict_empty_and_unknown_queue_behavior_remains_empty()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn($this->getBaseQueues());
        });

        $service = app(ZnunyAssignmentDependencyService::class);

        $options1 = $service->getStrictOwnerOptionsForQueue('');
        $this->assertEquals([], $options1);

        $options2 = $service->getStrictOwnerOptionsForQueue('UnknownQ');
        $this->assertEquals([], $options2);
    }

    public function test_empty_owner_id_or_login_returns_all_valid_filtered_queues()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn($this->getBaseQueues());
        });

        $service = app(ZnunyAssignmentDependencyService::class);

        $options1 = $service->getQueueOptionsForOwnerId('');
        // Q1 and Q2 are valid
        $this->assertCount(2, $options1);

        $options2 = $service->getQueueOptionsForOwnerLogin('');
        $this->assertCount(2, $options2);
    }

    public function test_unknown_owner_login_preserves_all_valid_queues_fallback()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn($this->getBaseQueues());
        });

        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgents')->andReturn($this->getBaseAgents());
        });

        $service = app(ZnunyAssignmentDependencyService::class);
        $options = $service->getQueueOptionsForOwnerLogin('unknown_agent');

        $this->assertCount(2, $options);
    }

    public function test_excluded_owner_login_returns_empty()
    {
        $this->mock(ZnunyUiFilterService::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAgentLoginExcluded')->with('excluded_agent')->andReturn(true);
        });

        $service = app(ZnunyAssignmentDependencyService::class);
        $options = $service->getQueueOptionsForOwnerLogin('excluded_agent');

        $this->assertEquals([], $options);
    }

    public function test_validation_with_missing_input_preserves_semantics()
    {
        $service = app(ZnunyAssignmentDependencyService::class);

        $this->assertTrue($service->isOwnerValidForQueue('', 'Q1'));
        $this->assertTrue($service->isOwnerValidForQueue('10', ''));

        $this->assertFalse($service->isOwnerStrictlyValidForQueue('', 'Q1'));
        $this->assertFalse($service->isOwnerStrictlyValidForQueue('10', ''));
    }

    public function test_valid_matrix_relation_returns_true()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn($this->getBaseQueues());
        });

        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgentIdsForQueue')->with(1)->andReturn([10]);
            $mock->shouldReceive('getAgents')->andReturn($this->getBaseAgents());
        });

        $service = app(ZnunyAssignmentDependencyService::class);
        $this->assertTrue($service->isOwnerValidForQueue('10', 'Q1'));
        $this->assertTrue($service->isOwnerStrictlyValidForQueue('10', 'Q1'));
    }

    public function test_invalid_matrix_relation_returns_false()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn($this->getBaseQueues());
        });

        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgentIdsForQueue')->with(1)->andReturn([10]);
            $mock->shouldReceive('getAgents')->andReturn($this->getBaseAgents());
        });

        $service = app(ZnunyAssignmentDependencyService::class);
        // Agent 20 is not assigned to Q1 (id 1)
        $this->assertFalse($service->isOwnerValidForQueue('20', 'Q1'));
        $this->assertFalse($service->isOwnerStrictlyValidForQueue('20', 'Q1'));
    }

    public function test_missing_malformed_reader_data_never_causes_live_fallback()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('getQueueByName');
            $mock->shouldNotReceive('getQueues');
            $mock->shouldNotReceive('getAgents');
            $mock->shouldNotReceive('getAgentAssignableQueues');
        });

        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn([]);
        });

        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgentIdsForQueue')->andReturn([]);
            $mock->shouldReceive('getAgents')->andReturn([]);
        });

        $service = app(ZnunyAssignmentDependencyService::class);
        $options = $service->getOwnerOptionsForQueue('Q1');
        $this->assertEquals([], $options);
    }
}
