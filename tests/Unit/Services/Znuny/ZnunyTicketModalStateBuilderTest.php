<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\ZnunyAgentService;
use App\Services\Znuny\ZnunyCachedLookupService;
use App\Services\Znuny\ZnunyLookupService;
use App\Services\Znuny\ZnunyQueueService;
use App\Services\Znuny\ZnunyTicketAdvancedDefaultsService;
use App\Services\Znuny\ZnunyTicketModalStateBuilder;
use App\Services\Znuny\ZnunyUiFilterService;
use Tests\TestCase;

class ZnunyTicketModalStateBuilderTest extends TestCase
{
    private function getBuilder($agentService, $queueService, $lookupService, $advancedDefaultsService, $cachedLookupService = null)
    {
        if (! $cachedLookupService) {
            $cachedLookupService = $this->mock(ZnunyCachedLookupService::class);
            $cachedLookupService->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->andReturn([]);
        }

        $cachedLookupService->shouldReceive('getPrewarmDatasetState')->with('queues')->byDefault()->andReturn(['available' => true, 'status' => 'ready']);
        $cachedLookupService->shouldReceive('getPrewarmDatasetState')->with('agents')->byDefault()->andReturn(['available' => true, 'status' => 'ready']);
        $cachedLookupService->shouldReceive('getPrewarmDatasetState')->with('customer_users')->byDefault()->andReturn(['available' => true, 'status' => 'ready']);
        $cachedLookupService->shouldReceive('getPrewarmDatasetState')->with('lookups')->byDefault()->andReturn(['available' => true, 'status' => 'ready']);

        return new ZnunyTicketModalStateBuilder(
            $agentService,
            $queueService,
            $lookupService,
            $advancedDefaultsService,
            $cachedLookupService
        );
    }

    public function test_build_state_valid_queue_exact_prewarmed_options()
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
            'warnings' => [],
        ]);

        $advancedDefaultsService = $this->mock(ZnunyTicketAdvancedDefaultsService::class);
        $advancedDefaultsService->shouldReceive('getDefaults')->andReturn([
            'priority' => '3 normal',
            'state' => 'new',
            'lock' => 'lock',
        ]);

        $uiFilterService = $this->mock(ZnunyUiFilterService::class);
        $uiFilterService->shouldReceive('isQueueExcluded')->andReturn(false);

        $cachedLookupService = $this->mock(ZnunyCachedLookupService::class);
        $cachedLookupService->shouldReceive('getCustomerUserPrimaryOptionsForQueue')
            ->with('QueueA')
            ->andReturn(['user1' => 'Test User <user1>', 'user2' => 'Another User <user2>']);

        $builder = $this->getBuilder($agentService, $queueService, $lookupService, $advancedDefaultsService, $cachedLookupService);

        $state = $builder->buildState('test-host');

        $this->assertEquals(['1' => 'Agent One <agent1>'], $state['agent_options']);
        $this->assertEquals(['QueueA' => 'QueueA Label'], $state['queue_options']);
        $this->assertNull($state['default_owner_id']);
        $this->assertEquals('QueueA', $state['default_queue']);
        $this->assertEquals('user1', $state['default_customer_user']);
        $this->assertEquals(['user1' => 'Test User <user1>', 'user2' => 'Another User <user2>'], $state['customer_user_options']);
        $this->assertEquals([], $state['warnings']);
    }

    public function test_build_state_missing_from_queue_map_added_with_cache_label()
    {
        $agentService = $this->mock(ZnunyAgentService::class);
        $agentService->shouldReceive('getSelectableAgents')->andReturn([]);
        $queueService = $this->mock(ZnunyQueueService::class);
        $queueService->shouldReceive('getSelectableQueuesResult')->andReturn(['options' => ['QueueA' => 'QueueA Label'], 'error' => null]);
        $lookupService = $this->mock(ZnunyLookupService::class);
        $lookupService->shouldReceive('resolveTicketDefaultCandidates')->with('test-host')->andReturn([
            'host_name' => 'test-host',
            'queue' => ['name' => 'QueueA', 'found' => true],
            'customer_user' => ['login' => 'missing1', 'found' => true],
            'warnings' => [],
        ]);
        $advancedDefaultsService = $this->mock(ZnunyTicketAdvancedDefaultsService::class);
        $advancedDefaultsService->shouldReceive('getDefaults')->andReturn([
            'priority' => '3 normal',
            'state' => 'new',
            'lock' => 'lock',
        ]);

        $uiFilterService = $this->mock(ZnunyUiFilterService::class);
        $uiFilterService->shouldReceive('isQueueExcluded')->andReturn(false);

        $cachedLookupService = $this->mock(ZnunyCachedLookupService::class);
        $cachedLookupService->shouldReceive('getCustomerUserPrimaryOptionsForQueue')
            ->with('QueueA')
            ->andReturn(['user1' => 'Test User <user1>']);

        $cachedLookupService->shouldReceive('getCustomerUserLabel')
            ->with('missing1')
            ->andReturn('Missing User <missing1>');

        $builder = $this->getBuilder($agentService, $queueService, $lookupService, $advancedDefaultsService, $cachedLookupService);
        $state = $builder->buildState('test-host');

        $this->assertEquals('missing1', $state['default_customer_user']);
        $this->assertEquals(['user1' => 'Test User <user1>', 'missing1' => 'Missing User <missing1>'], $state['customer_user_options']);
    }

    public function test_build_state_missing_cached_label_falls_back_to_login()
    {
        $agentService = $this->mock(ZnunyAgentService::class);
        $agentService->shouldReceive('getSelectableAgents')->andReturn([]);
        $queueService = $this->mock(ZnunyQueueService::class);
        $queueService->shouldReceive('getSelectableQueuesResult')->andReturn(['options' => ['QueueA' => 'QueueA Label'], 'error' => null]);
        $lookupService = $this->mock(ZnunyLookupService::class);
        $lookupService->shouldReceive('resolveTicketDefaultCandidates')->with('test-host')->andReturn([
            'host_name' => 'test-host',
            'queue' => ['name' => 'QueueA', 'found' => true],
            'customer_user' => ['login' => 'missing1', 'found' => true],
            'warnings' => [],
        ]);
        $advancedDefaultsService = $this->mock(ZnunyTicketAdvancedDefaultsService::class);
        $advancedDefaultsService->shouldReceive('getDefaults')->andReturn([
            'priority' => '3 normal',
            'state' => 'new',
            'lock' => 'lock',
        ]);

        $uiFilterService = $this->mock(ZnunyUiFilterService::class);
        $uiFilterService->shouldReceive('isQueueExcluded')->andReturn(false);

        $cachedLookupService = $this->mock(ZnunyCachedLookupService::class);
        $cachedLookupService->shouldReceive('getCustomerUserPrimaryOptionsForQueue')
            ->with('QueueA')
            ->andReturn(['user1' => 'Test User <user1>']);

        $cachedLookupService->shouldReceive('getCustomerUserLabel')
            ->with('missing1')
            ->andReturnNull();

        $builder = $this->getBuilder($agentService, $queueService, $lookupService, $advancedDefaultsService, $cachedLookupService);
        $state = $builder->buildState('test-host');

        $this->assertEquals('missing1', $state['default_customer_user']);
        $this->assertEquals(['user1' => 'Test User <user1>', 'missing1' => 'missing1'], $state['customer_user_options']);
    }

    public function test_build_state_missing_queue_snapshot_returns_safe_options()
    {
        $agentService = $this->mock(ZnunyAgentService::class);
        $agentService->shouldReceive('getSelectableAgents')->andReturn([]);
        $queueService = $this->mock(ZnunyQueueService::class);
        $queueService->shouldReceive('getSelectableQueuesResult')->andReturn(['options' => ['QueueA' => 'QueueA Label'], 'error' => null]);
        $lookupService = $this->mock(ZnunyLookupService::class);
        $lookupService->shouldReceive('resolveTicketDefaultCandidates')->with('test-host')->andReturn([
            'host_name' => 'test-host',
            'queue' => ['name' => 'QueueA', 'found' => true],
            'customer_user' => ['login' => 'user1', 'found' => true],
            'warnings' => [],
        ]);
        $advancedDefaultsService = $this->mock(ZnunyTicketAdvancedDefaultsService::class);
        $advancedDefaultsService->shouldReceive('getDefaults')->andReturn([
            'priority' => '3 normal',
            'state' => 'new',
            'lock' => 'lock',
        ]);

        $uiFilterService = $this->mock(ZnunyUiFilterService::class);
        $uiFilterService->shouldReceive('isQueueExcluded')->andReturn(false);

        $cachedLookupService = $this->mock(ZnunyCachedLookupService::class);
        $cachedLookupService->shouldReceive('getCustomerUserPrimaryOptionsForQueue')
            ->with('QueueA')
            ->andReturn([]);

        $cachedLookupService->shouldReceive('getCustomerUserLabel')
            ->with('user1')
            ->andReturnNull();

        $builder = $this->getBuilder($agentService, $queueService, $lookupService, $advancedDefaultsService, $cachedLookupService);
        $state = $builder->buildState('test-host');

        $this->assertEquals('QueueA', $state['default_queue']);
        $this->assertEquals('user1', $state['default_customer_user']);
        $this->assertEquals(['user1' => 'user1'], $state['customer_user_options']);
    }

    public function test_build_state_queue_exclusion_unchanged()
    {
        $agentService = $this->mock(ZnunyAgentService::class);
        $agentService->shouldReceive('getSelectableAgents')->andReturn([]);
        $queueService = $this->mock(ZnunyQueueService::class);
        $queueService->shouldReceive('getSelectableQueuesResult')->andReturn(['options' => ['QueueA' => 'QueueA Label'], 'error' => null]);
        $lookupService = $this->mock(ZnunyLookupService::class);

        $lookupService->shouldReceive('resolveTicketDefaultCandidates')->with('test-host')->andReturn([
            'host_name' => 'test-host',
            'queue' => ['name' => 'ExcludedQueue', 'found' => true],
            'customer_user' => ['login' => 'user1', 'found' => true],
            'warnings' => [],
        ]);

        $advancedDefaultsService = $this->mock(ZnunyTicketAdvancedDefaultsService::class);
        $advancedDefaultsService->shouldReceive('getDefaults')->andReturn([
            'priority' => '3 normal',
            'state' => 'new',
            'lock' => 'lock',
        ]);

        $uiFilterService = $this->mock(ZnunyUiFilterService::class);
        $uiFilterService->shouldReceive('isQueueExcluded')->with('ExcludedQueue', null)->once()->andReturn(true);

        $cachedLookupService = $this->mock(ZnunyCachedLookupService::class);
        $cachedLookupService->shouldNotReceive('getCustomerUserPrimaryOptionsForQueue');
        $cachedLookupService->shouldReceive('getCustomerUserLabel')->with('user1')->once()->andReturn('User One <user1>');

        $builder = $this->getBuilder($agentService, $queueService, $lookupService, $advancedDefaultsService, $cachedLookupService);
        $state = $builder->buildState('test-host');

        $this->assertNull($state['default_queue']);
        $this->assertEquals('user1', $state['default_customer_user']);
        $this->assertEquals(['user1' => 'User One <user1>'], $state['customer_user_options']);
        $this->assertContains("Default queue 'ExcludedQueue' is excluded by your queue filters. Please select a different queue.", $state['warnings']);
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

        $advancedDefaultsService = $this->mock(ZnunyTicketAdvancedDefaultsService::class);
        $advancedDefaultsService->shouldReceive('getDefaults')->andReturn([
            'priority' => '3 normal',
            'state' => 'new',
            'lock' => 'lock',
        ]);

        $builder = $this->getBuilder($agentService, $queueService, $lookupService, $advancedDefaultsService);

        $state = $builder->buildState('test-host');

        $this->assertNull($state['default_queue']);
        $this->assertNull($state['default_customer_user']);
        $this->assertContains('Lookup failed: Test Error', $state['warnings']);
    }

    public function test_build_state_collects_warnings_for_stale_and_unavailable_datasets()
    {
        $agentService = $this->mock(ZnunyAgentService::class);
        $agentService->shouldReceive('getSelectableAgents')->andReturn([]);
        $queueService = $this->mock(ZnunyQueueService::class);
        $queueService->shouldReceive('getSelectableQueuesResult')->andReturn(['options' => [], 'error' => null]);
        $lookupService = $this->mock(ZnunyLookupService::class);
        $lookupService->shouldReceive('resolveTicketDefaultCandidates')->with('test-host')->andReturn([
            'host_name' => 'test-host',
            'queue' => ['name' => null, 'found' => false],
            'customer_user' => ['login' => null, 'found' => false],
            'warnings' => [],
        ]);
        $advancedDefaultsService = $this->mock(ZnunyTicketAdvancedDefaultsService::class);
        $advancedDefaultsService->shouldReceive('getDefaults')->andReturn([
            'priority' => '3 normal',
            'state' => 'new',
            'lock' => 'lock',
        ]);

        $cachedLookupService = $this->mock(ZnunyCachedLookupService::class);
        $cachedLookupService->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->andReturn([]);

        // specific states
        $cachedLookupService->shouldReceive('getPrewarmDatasetState')->with('queues')->andReturn(['available' => false, 'status' => 'missing']);
        $cachedLookupService->shouldReceive('getPrewarmDatasetState')->with('agents')->andReturn(['available' => true, 'status' => 'stale']);
        $cachedLookupService->shouldReceive('getPrewarmDatasetState')->with('customer_users')->andReturn(['available' => false, 'status' => 'failed']);
        $cachedLookupService->shouldReceive('getPrewarmDatasetState')->with('lookups')->andReturn(['available' => true, 'status' => 'refreshing']);

        $builder = $this->getBuilder($agentService, $queueService, $lookupService, $advancedDefaultsService, $cachedLookupService);
        $state = $builder->buildState('test-host');

        $this->assertSame([
            __('znuny_data_status.datasets.queues').': '.__('znuny_data_status.consumer.unavailable'),
            __('znuny_data_status.datasets.agents').': '.__('znuny_data_status.consumer.stale'),
            __('znuny_data_status.datasets.customer_users').': '.__('znuny_data_status.consumer.customer_users_unavailable_search_live'),
            __('znuny_data_status.datasets.lookups').': '.__('znuny_data_status.consumer.refreshing'),
        ], $state['warnings']);
    }

    public function test_build_state_omits_ready_datasets_from_warnings()
    {
        $agentService = $this->mock(ZnunyAgentService::class);
        $agentService->shouldReceive('getSelectableAgents')->andReturn([]);
        $queueService = $this->mock(ZnunyQueueService::class);
        $queueService->shouldReceive('getSelectableQueuesResult')->andReturn(['options' => [], 'error' => null]);
        $lookupService = $this->mock(ZnunyLookupService::class);
        $lookupService->shouldReceive('resolveTicketDefaultCandidates')->with('test-host')->andReturn([
            'host_name' => 'test-host',
            'queue' => ['name' => null, 'found' => false],
            'customer_user' => ['login' => null, 'found' => false],
            'warnings' => [],
        ]);
        $advancedDefaultsService = $this->mock(ZnunyTicketAdvancedDefaultsService::class);
        $advancedDefaultsService->shouldReceive('getDefaults')->andReturn([
            'priority' => '3 normal',
            'state' => 'new',
            'lock' => 'lock',
        ]);

        $cachedLookupService = $this->mock(ZnunyCachedLookupService::class);
        $cachedLookupService->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->andReturn([]);

        $cachedLookupService->shouldReceive('getPrewarmDatasetState')->with('queues')->andReturn(['available' => true, 'status' => 'ready']);
        $cachedLookupService->shouldReceive('getPrewarmDatasetState')->with('agents')->andReturn(['available' => true, 'status' => 'ready']);
        $cachedLookupService->shouldReceive('getPrewarmDatasetState')->with('customer_users')->andReturn(['available' => true, 'status' => 'ready']);
        $cachedLookupService->shouldReceive('getPrewarmDatasetState')->with('lookups')->andReturn(['available' => true, 'status' => 'ready']);

        $builder = $this->getBuilder($agentService, $queueService, $lookupService, $advancedDefaultsService, $cachedLookupService);
        $state = $builder->buildState('test-host');

        $this->assertEmpty($state['warnings']);
    }

    public function test_build_state_deduplicates_and_orders_dataset_warnings()
    {
        $agentService = $this->mock(ZnunyAgentService::class);
        $agentService->shouldReceive('getSelectableAgents')->andReturn([]);
        $queueService = $this->mock(ZnunyQueueService::class);
        $queueService->shouldReceive('getSelectableQueuesResult')->andReturn(['options' => [], 'error' => null]);
        $lookupService = $this->mock(ZnunyLookupService::class);
        $lookupService->shouldReceive('resolveTicketDefaultCandidates')->with('test-host')->andReturn([
            'host_name' => 'test-host',
            'queue' => ['name' => null, 'found' => false],
            'customer_user' => ['login' => null, 'found' => false],
            'warnings' => [
                __('znuny_data_status.datasets.queues').': '.__('znuny_data_status.consumer.unavailable'), // Duplicate from candidates
                'Some candidate warning',
            ],
        ]);
        $advancedDefaultsService = $this->mock(ZnunyTicketAdvancedDefaultsService::class);
        $advancedDefaultsService->shouldReceive('getDefaults')->andReturn([
            'priority' => '3 normal',
            'state' => 'new',
            'lock' => 'lock',
        ]);

        $cachedLookupService = $this->mock(ZnunyCachedLookupService::class);
        $cachedLookupService->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->andReturn([]);

        $cachedLookupService->shouldReceive('getPrewarmDatasetState')->with('queues')->andReturn(['available' => false, 'status' => 'failed']);
        $cachedLookupService->shouldReceive('getPrewarmDatasetState')->with('agents')->andReturn(['available' => true, 'status' => 'stale']);
        $cachedLookupService->shouldReceive('getPrewarmDatasetState')->with('customer_users')->andReturn(['available' => false, 'status' => 'missing']);
        $cachedLookupService->shouldReceive('getPrewarmDatasetState')->with('lookups')->andReturn(['available' => true, 'status' => 'refreshing']);

        $builder = $this->getBuilder($agentService, $queueService, $lookupService, $advancedDefaultsService, $cachedLookupService);
        $state = $builder->buildState('test-host');

        $expectedWarnings = [
            __('znuny_data_status.datasets.queues').': '.__('znuny_data_status.consumer.unavailable'),
            __('znuny_data_status.datasets.agents').': '.__('znuny_data_status.consumer.stale'),
            __('znuny_data_status.datasets.customer_users').': '.__('znuny_data_status.consumer.customer_users_unavailable_search_live'),
            __('znuny_data_status.datasets.lookups').': '.__('znuny_data_status.consumer.refreshing'),
            'Some candidate warning',
        ];

        $this->assertSame($expectedWarnings, $state['warnings']);
    }

    public function test_build_state_stale_queue_remains_usable()
    {
        $agentService = $this->mock(ZnunyAgentService::class);
        $agentService->shouldReceive('getSelectableAgents')->andReturn([]);
        $queueService = $this->mock(ZnunyQueueService::class);
        $queueService->shouldReceive('getSelectableQueuesResult')->andReturn(['options' => ['QueueA' => 'QueueA Label'], 'error' => null]);
        $lookupService = $this->mock(ZnunyLookupService::class);
        $lookupService->shouldReceive('resolveTicketDefaultCandidates')->with('test-host')->andReturn([
            'host_name' => 'test-host',
            'queue' => ['name' => 'QueueA', 'found' => true],
            'customer_user' => ['login' => 'user1', 'found' => true],
            'warnings' => [],
        ]);
        $advancedDefaultsService = $this->mock(ZnunyTicketAdvancedDefaultsService::class);
        $advancedDefaultsService->shouldReceive('getDefaults')->andReturn([
            'priority' => '3 normal',
            'state' => 'new',
            'lock' => 'lock',
        ]);

        $cachedLookupService = $this->mock(ZnunyCachedLookupService::class);
        $cachedLookupService->shouldReceive('getCustomerUserPrimaryOptionsForQueue')
            ->with('QueueA')
            ->andReturn(['user1' => 'Test User <user1>']);

        $cachedLookupService->shouldReceive('getPrewarmDatasetState')->with('queues')->andReturn(['available' => true, 'status' => 'stale']);

        $uiFilterService = $this->mock(ZnunyUiFilterService::class);
        $uiFilterService->shouldReceive('isQueueExcluded')->with('QueueA', null)->andReturn(false);

        $builder = $this->getBuilder($agentService, $queueService, $lookupService, $advancedDefaultsService, $cachedLookupService);
        $state = $builder->buildState('test-host');

        $this->assertEquals('QueueA', $state['default_queue']);
        $this->assertEquals(['QueueA' => 'QueueA Label'], $state['queue_options']);
        $this->assertEquals(['user1' => 'Test User <user1>'], $state['customer_user_options']);

        $expectedWarning = __('znuny_data_status.datasets.queues').': '.__('znuny_data_status.consumer.stale');
        $this->assertContains($expectedWarning, $state['warnings']);
        $this->assertCount(1, $state['warnings']);
        $this->assertStringNotContainsString(__('znuny_data_status.consumer.unavailable'), $expectedWarning);
    }

    public function test_build_state_refreshing_customer_user_remains_usable()
    {
        $agentService = $this->mock(ZnunyAgentService::class);
        $agentService->shouldReceive('getSelectableAgents')->andReturn([]);
        $queueService = $this->mock(ZnunyQueueService::class);
        $queueService->shouldReceive('getSelectableQueuesResult')->andReturn(['options' => ['QueueA' => 'QueueA Label'], 'error' => null]);
        $lookupService = $this->mock(ZnunyLookupService::class);
        $lookupService->shouldReceive('resolveTicketDefaultCandidates')->with('test-host')->andReturn([
            'host_name' => 'test-host',
            'queue' => ['name' => 'QueueA', 'found' => true],
            'customer_user' => ['login' => 'user1', 'found' => true],
            'warnings' => [],
        ]);
        $advancedDefaultsService = $this->mock(ZnunyTicketAdvancedDefaultsService::class);
        $advancedDefaultsService->shouldReceive('getDefaults')->andReturn([
            'priority' => '3 normal',
            'state' => 'new',
            'lock' => 'lock',
        ]);

        $cachedLookupService = $this->mock(ZnunyCachedLookupService::class);
        $cachedLookupService->shouldReceive('getCustomerUserPrimaryOptionsForQueue')
            ->with('QueueA')
            ->andReturn(['user1' => 'Test User <user1>']);

        $cachedLookupService->shouldReceive('getPrewarmDatasetState')->with('customer_users')->andReturn(['available' => true, 'status' => 'refreshing']);

        $uiFilterService = $this->mock(ZnunyUiFilterService::class);
        $uiFilterService->shouldReceive('isQueueExcluded')->with('QueueA', null)->andReturn(false);

        $builder = $this->getBuilder($agentService, $queueService, $lookupService, $advancedDefaultsService, $cachedLookupService);
        $state = $builder->buildState('test-host');

        $this->assertEquals('user1', $state['default_customer_user']);
        $this->assertEquals(['user1' => 'Test User <user1>'], $state['customer_user_options']);

        $expectedWarning = __('znuny_data_status.datasets.customer_users').': '.__('znuny_data_status.consumer.refreshing');
        $this->assertContains($expectedWarning, $state['warnings']);
        $this->assertCount(1, $state['warnings']);
        $this->assertStringNotContainsString(__('znuny_data_status.consumer.customer_users_unavailable_search_live'), $expectedWarning);
    }
}
