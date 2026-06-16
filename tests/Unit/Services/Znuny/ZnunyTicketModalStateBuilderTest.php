<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\ZnunyAgentService;
use App\Services\Znuny\ZnunyLookupService;
use App\Services\Znuny\ZnunyQueueService;
use App\Services\Znuny\ZnunyTicketModalStateBuilder;
use Tests\TestCase;

class ZnunyTicketModalStateBuilderTest extends TestCase
{
    public function test_build_state()
    {
        $agentService = $this->mock(ZnunyAgentService::class);
        $agentService->shouldReceive('getSelectableAgents')->andReturn([
            ['id' => 1, 'login' => 'agent1', 'label' => 'Agent One <agent1>'],
        ]);

        $queueService = $this->mock(ZnunyQueueService::class);
        $queueService->shouldReceive('getSelectableQueuesResult')->andReturn([
            'options' => ['QueueA' => 'QueueA Label'],
            'error' => null,
        ]);

        $lookupService = $this->mock(ZnunyLookupService::class);
        $lookupService->shouldReceive('resolveTicketDefaultCandidates')->with('test-host')->andReturn([
            'host_name' => 'test-host',
            'queue' => ['name' => 'QueueA', 'found' => true],
            'customer_user' => ['login' => 'user1', 'found' => true],
            'warnings' => ['A warning'],
        ]);

        $builder = new ZnunyTicketModalStateBuilder($agentService, $queueService, $lookupService);

        $state = $builder->buildState('test-host');

        $this->assertEquals(['1' => 'Agent One <agent1>'], $state['agent_options']);
        $this->assertEquals(['QueueA' => 'QueueA Label'], $state['queue_options']);
        $this->assertNull($state['default_owner_id']);
        $this->assertEquals('QueueA', $state['default_queue']);
        $this->assertEquals('user1', $state['default_customer_user']);
        $this->assertEquals(['user1' => 'user1'], $state['customer_user_options']);
        $this->assertEquals(['A warning'], $state['warnings']);
    }

    public function test_build_state_lookup_exception()
    {
        $agentService = $this->mock(ZnunyAgentService::class);
        $agentService->shouldReceive('getSelectableAgents')->andReturn([]);

        $queueService = $this->mock(ZnunyQueueService::class);
        $queueService->shouldReceive('getSelectableQueuesResult')->andReturn([
            'options' => [],
            'error' => null,
        ]);

        $lookupService = $this->mock(ZnunyLookupService::class);
        $lookupService->shouldReceive('resolveTicketDefaultCandidates')->with('test-host')->andThrow(new \Exception('Test Error'));

        $builder = new ZnunyTicketModalStateBuilder($agentService, $queueService, $lookupService);

        $state = $builder->buildState('test-host');

        $this->assertNull($state['default_queue']);
        $this->assertNull($state['default_customer_user']);
        $this->assertContains('Lookup failed: Test Error', $state['warnings']);
    }
}
