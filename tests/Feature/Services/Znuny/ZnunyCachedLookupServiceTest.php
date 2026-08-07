<?php

namespace Tests\Feature\Services\Znuny;

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\Znuny\Cache\ZnunyAgentCacheReadService;
use App\Services\Znuny\Cache\ZnunyCustomerUserCacheReadService;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use App\Services\Znuny\Cache\ZnunyQueueCacheReadService;
use App\Services\Znuny\ZnunyCachedLookupService;
use App\Services\Znuny\ZnunyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ZnunyCachedLookupServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(SettingsService::class)->clearAllCaches();
        parent::tearDown();
    }

    public function test_get_all_queues_returns_payload_from_reader()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('getQueues');
        });

        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->twice()->andReturn([
                ['id' => 1, 'name' => 'Raw', 'label' => 'Raw'],
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);

        $queues1 = $service->getAllQueues();
        $this->assertCount(1, $queues1);

        $queues2 = $service->getAllQueues();
        $this->assertCount(1, $queues2);
    }

    public function test_exception_in_reader_returns_empty_array_with_no_live_fallback()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('getQueues');
        });

        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->once()->andThrow(new \Exception('Reader Error'));
        });

        $service = app(ZnunyCachedLookupService::class);

        $queues1 = $service->getAllQueues();
        $this->assertEquals([], $queues1);
    }

    public function test_empty_reader_result_returns_empty_with_no_fallback()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('getQueues');
        });

        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->once()->andReturn([]);
        });

        $service = app(ZnunyCachedLookupService::class);

        $queues1 = $service->getAllQueues();
        $this->assertEquals([], $queues1);
    }

    public function test_filters_queues_with_regexes()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('getQueues');
        });

        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn([
                ['id' => 1, 'name' => 'Raw', 'label' => 'Raw'],
                ['id' => 2, 'name' => 'Network', 'label' => 'Network'],
                ['id' => 3, 'name' => 'TestQueue', 'label' => 'TestQueue'],
            ]);
        });

        Setting::updateOrCreate(
            ['key' => 'znuny_global_queue_exclusion_regexes'],
            [
                'type' => 'json',
                'value' => json_encode([
                    ['regex' => '^Test.*'],
                    ['regex' => '.*work$'],
                ]),
            ]
        );

        $service = app(ZnunyCachedLookupService::class);
        $filtered = $service->getFilteredQueueOptions();

        $this->assertCount(1, $filtered);
        $this->assertArrayHasKey('Raw', $filtered);
        $this->assertArrayNotHasKey('Network', $filtered);
        $this->assertArrayNotHasKey('TestQueue', $filtered);
    }

    public function test_handles_invalid_regex_safely()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('getQueues');
        });

        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn([
                ['id' => 1, 'name' => 'Raw', 'label' => 'Raw'],
            ]);
        });

        Setting::updateOrCreate(
            ['key' => 'znuny_global_queue_exclusion_regexes'],
            [
                'type' => 'json',
                'value' => json_encode([
                    ['regex' => '***invalid***'],
                    ['regex' => '^Exclude'],
                ]),
            ]
        );

        $service = app(ZnunyCachedLookupService::class);
        $filtered = $service->getFilteredQueueOptions();

        $this->assertCount(1, $filtered);
        $this->assertArrayHasKey('Raw', $filtered);
    }

    public function test_gets_assignable_owner_options_from_readers()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('getQueues');
            $mock->shouldNotReceive('getQueueAssignableAgents');
        });

        $this->mock(ZnunyQueueCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn([
                ['id' => 1, 'name' => 'Raw', 'label' => 'Raw'],
            ]);
        });

        $this->mock(ZnunyAgentCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAgentIdsForQueue')->with(1)->once()->andReturn([10]);
            $mock->shouldReceive('getAgents')->once()->andReturn([
                ['id' => 10, 'label' => 'John Doe', 'login' => 'johndoe'],
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);

        $owners1 = $service->getAssignableOwnerOptionsForQueue('Raw');
        $this->assertEquals([10 => 'John Doe'], $owners1);
    }

    public function test_get_ticket_states_returns_exact_reader_map()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('getTicketStates');
        });

        $this->mock(ZnunyLookupCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getStates')->once()->andReturn([
                'open' => 'open',
                'closed successful' => 'closed successful',
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);
        $states = $service->getTicketStates();

        $this->assertEquals([
            'open' => 'open',
            'closed successful' => 'closed successful',
        ], $states);
    }

    public function test_get_ticket_priorities_returns_exact_reader_map()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('getTicketPriorities');
        });

        $this->mock(ZnunyLookupCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getPriorities')->once()->andReturn([
                '3 normal' => '3 normal',
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);
        $priorities = $service->getTicketPriorities();

        $this->assertEquals(['3 normal' => '3 normal'], $priorities);
    }

    public function test_get_ticket_types_returns_exact_reader_map()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('getTicketTypes');
        });

        $this->mock(ZnunyLookupCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getTypes')->once()->andReturn([
                'Incident' => 'Incident',
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);
        $types = $service->getTicketTypes();

        $this->assertEquals(['Incident' => 'Incident'], $types);
    }

    public function test_reader_empty_array_is_returned_as_empty_array()
    {
        $this->mock(ZnunyLookupCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getStates')->once()->andReturn([]);
            $mock->shouldReceive('getPriorities')->once()->andReturn([]);
            $mock->shouldReceive('getTypes')->once()->andReturn([]);
        });

        $service = app(ZnunyCachedLookupService::class);

        $this->assertEquals([], $service->getTicketStates());
        $this->assertEquals([], $service->getTicketPriorities());
        $this->assertEquals([], $service->getTicketTypes());
    }

    public function test_reader_exception_returns_empty_array_safely()
    {
        $this->mock(ZnunyLookupCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getStates')->once()->andThrow(new \Exception('States error'));
            $mock->shouldReceive('getPriorities')->once()->andThrow(new \Exception('Priorities error'));
            $mock->shouldReceive('getTypes')->once()->andThrow(new \Exception('Types error'));
        });

        $service = app(ZnunyCachedLookupService::class);

        $this->assertEquals([], $service->getTicketStates());
        $this->assertEquals([], $service->getTicketPriorities());
        $this->assertEquals([], $service->getTicketTypes());
    }

    public function test_repeated_calls_read_through_reader_and_do_not_use_caching()
    {
        $this->mock(ZnunyLookupCacheReadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getStates')->twice()->andReturn(['s' => 's']);
            $mock->shouldReceive('getPriorities')->twice()->andReturn(['p' => 'p']);
            $mock->shouldReceive('getTypes')->twice()->andReturn(['t' => 't']);
        });

        $service = app(ZnunyCachedLookupService::class);

        $service->getTicketStates();
        $service->getTicketStates();

        $service->getTicketPriorities();
        $service->getTicketPriorities();

        $service->getTicketTypes();
        $service->getTicketTypes();
    }

    public function test_get_customer_user_primary_options_for_queue_returns_exact_reader_map()
    {
        $this->mock(ZnunyClient::class, function ($mock) {
            $mock->shouldNotReceive('getCustomerUser');
            $mock->shouldNotReceive('searchCustomerUsers');
        });

        $this->mock(ZnunyCustomerUserCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getOptionsForQueue')->with('QueueA')->once()->andReturn(['user1' => 'Label1']);
        });

        $service = app(ZnunyCachedLookupService::class);
        $options = $service->getCustomerUserPrimaryOptionsForQueue('QueueA');
        $this->assertEquals(['user1' => 'Label1'], $options);
    }

    public function test_get_customer_user_primary_options_empty_queue_returns_empty_without_reader()
    {
        $this->mock(ZnunyClient::class, function ($mock) {
            $mock->shouldNotReceive('getCustomerUser');
            $mock->shouldNotReceive('searchCustomerUsers');
        });

        $this->mock(ZnunyCustomerUserCacheReadService::class, function ($mock) {
            $mock->shouldNotReceive('getOptionsForQueue');
        });

        $service = app(ZnunyCachedLookupService::class);
        $this->assertEquals([], $service->getCustomerUserPrimaryOptionsForQueue(''));
        $this->assertEquals([], $service->getCustomerUserPrimaryOptionsForQueue('   '));
    }

    public function test_get_customer_user_primary_options_reader_empty_remains_empty()
    {
        $this->mock(ZnunyCustomerUserCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getOptionsForQueue')->with('QueueA')->once()->andReturn([]);
        });
        $service = app(ZnunyCachedLookupService::class);
        $this->assertEquals([], $service->getCustomerUserPrimaryOptionsForQueue('QueueA'));
    }

    public function test_get_customer_user_primary_options_reader_exception_reports_and_returns_empty()
    {
        $this->mock(ZnunyCustomerUserCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getOptionsForQueue')->with('QueueA')->once()->andThrow(new \Exception('Reader error'));
        });
        $service = app(ZnunyCachedLookupService::class);
        $this->assertEquals([], $service->getCustomerUserPrimaryOptionsForQueue('QueueA'));
    }

    public function test_get_customer_user_primary_options_repeated_calls_invoke_reader_repeatedly()
    {
        $this->mock(ZnunyCustomerUserCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getOptionsForQueue')->with('QueueA')->twice()->andReturn(['u1' => 'L1']);
        });
        $service = app(ZnunyCachedLookupService::class);
        $service->getCustomerUserPrimaryOptionsForQueue('QueueA');
        $service->getCustomerUserPrimaryOptionsForQueue('QueueA');
    }

    public function test_get_customer_user_search_terms_returns_exact_reader_list()
    {
        $this->mock(ZnunyCustomerUserCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getSearchTermsForQueue')->with('QueueA')->once()->andReturn(['term1', 'term2']);
        });
        $service = app(ZnunyCachedLookupService::class);
        $this->assertEquals(['term1', 'term2'], $service->getCustomerUserSearchTerms('QueueA'));
    }

    public function test_get_customer_user_search_terms_empty_queue_returns_empty()
    {
        $this->mock(ZnunyCustomerUserCacheReadService::class, function ($mock) {
            $mock->shouldNotReceive('getSearchTermsForQueue');
        });
        $service = app(ZnunyCachedLookupService::class);
        $this->assertEquals([], $service->getCustomerUserSearchTerms(''));
    }

    public function test_get_customer_user_search_terms_reader_miss_and_exception_return_empty()
    {
        $this->mock(ZnunyCustomerUserCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getSearchTermsForQueue')->with('QueueA')->once()->andReturn([]);
            $mock->shouldReceive('getSearchTermsForQueue')->with('QueueA')->once()->andThrow(new \Exception('error'));
        });
        $service = app(ZnunyCachedLookupService::class);
        $this->assertEquals([], $service->getCustomerUserSearchTerms('QueueA'));
        $this->assertEquals([], $service->getCustomerUserSearchTerms('QueueA'));
    }

    public function test_get_customer_user_label_returns_first_matching_in_snapshot_order()
    {
        $this->mock(ZnunyClient::class, function ($mock) {
            $mock->shouldNotReceive('getCustomerUser');
            $mock->shouldNotReceive('searchCustomerUsers');
        });

        $this->mock(ZnunyCustomerUserCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getSnapshot')->once()->andReturn([
                'queues' => [
                    ['queue_name' => 'Q1', 'options' => ['u1' => 'L1-Q1']],
                    ['queue_name' => 'Q2', 'options' => ['u2' => 'L2']],
                    ['queue_name' => 'Q3', 'options' => ['u1' => 'L1-Q3']], // duplicate login
                ],
            ]);
        });
        $service = app(ZnunyCachedLookupService::class);
        $this->assertEquals('L1-Q1', $service->getCustomerUserLabel('u1'));
    }

    public function test_get_customer_user_label_missing_login_returns_null()
    {
        $this->mock(ZnunyCustomerUserCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getSnapshot')->once()->andReturn([
                'queues' => [['queue_name' => 'Q1', 'options' => ['u1' => 'L1']]],
            ]);
        });
        $service = app(ZnunyCachedLookupService::class);
        $this->assertNull($service->getCustomerUserLabel('u2'));
    }

    public function test_get_customer_user_label_empty_login_returns_null()
    {
        $this->mock(ZnunyCustomerUserCacheReadService::class, function ($mock) {
            $mock->shouldNotReceive('getSnapshot');
        });
        $service = app(ZnunyCachedLookupService::class);
        $this->assertNull($service->getCustomerUserLabel(''));
    }

    public function test_get_customer_user_label_exception_returns_null()
    {
        $this->mock(ZnunyCustomerUserCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getSnapshot')->once()->andThrow(new \Exception('err'));
        });
        $service = app(ZnunyCachedLookupService::class);
        $this->assertNull($service->getCustomerUserLabel('u1'));
    }

    public function test_resolve_template_candidate_one_word_exact_match()
    {
        Setting::updateOrCreate(['key' => 'znuny_customer_user_from_queue_template'], ['value' => '<queue>123', 'type' => 'string']);

        $this->mock(ZnunyClient::class, function ($mock) {
            $mock->shouldNotReceive('getCustomerUser');
            $mock->shouldNotReceive('searchCustomerUsers');
        });

        $this->mock(\App\Services\Znuny\Cache\ZnunyCustomerUserCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getOptionsForQueue')->with('OneWord')->once()->andReturn(['OneWord123' => 'L']);
        });
        $service = app(ZnunyCachedLookupService::class);
        $this->assertEquals('OneWord123', $service->resolveTemplateCandidate('OneWord'));
    }

    public function test_resolve_template_candidate_multi_word_exact_match()
    {
        Setting::updateOrCreate(['key' => 'znuny_customer_user_from_queue_template'], ['value' => '<queue>@example.com', 'type' => 'string']);

        $this->mock(\App\Services\Znuny\Cache\ZnunyCustomerUserCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getOptionsForQueue')->with('Network Hardware')->once()->andReturn(['NetworkHardware@example.com' => 'L']);
        });
        $service = app(ZnunyCachedLookupService::class);
        $this->assertEquals('NetworkHardware@example.com', $service->resolveTemplateCandidate('Network Hardware'));
    }

    public function test_resolve_template_candidate_multi_word_fallback()
    {
        Setting::updateOrCreate(['key' => 'znuny_customer_user_from_queue_template'], ['value' => '<queue>-suffix', 'type' => 'string']);

        $this->mock(\App\Services\Znuny\Cache\ZnunyCustomerUserCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getOptionsForQueue')->with('Network Hardware')->once()->andReturn([
                'network-foo-suffix' => 'Foo',
                'network-hardware-suffix' => 'Hardware',
                'other-user' => 'L'
            ]);
        });
        $service = app(ZnunyCachedLookupService::class);
        $this->assertEquals('network-hardware-suffix', $service->resolveTemplateCandidate('Network Hardware'));
    }

    public function test_resolve_template_candidate_empty_options_return_null()
    {
        $this->mock(\App\Services\Znuny\Cache\ZnunyCustomerUserCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getOptionsForQueue')->with('Q1')->once()->andReturn([]);
        });
        $service = app(ZnunyCachedLookupService::class);
        $this->assertNull($service->resolveTemplateCandidate('Q1'));
    }

    public function test_search_customer_user_options_calls_client_once_with_trimmed_query()
    {
        $this->mock(ZnunyClient::class, function ($mock) {
            $mock->shouldReceive('searchCustomerUsers')->with('my search', 20)->once()->andReturn([
                ['login' => 'user1', 'label' => 'Label1'],
            ]);
        });
        $service = app(ZnunyCachedLookupService::class);
        $options = $service->searchCustomerUserOptions('  my search  ', 20);
        $this->assertEquals(['user1' => 'Label1'], $options);
    }

    public function test_search_customer_user_options_clamp_limit()
    {
        $this->mock(ZnunyClient::class, function ($mock) {
            $mock->shouldReceive('searchCustomerUsers')->with('search1', 1)->once()->andReturn([]);
            $mock->shouldReceive('searchCustomerUsers')->with('search2', 50)->once()->andReturn([]);
        });
        $service = app(ZnunyCachedLookupService::class);
        $service->searchCustomerUserOptions('search1', -10);
        $service->searchCustomerUserOptions('search2', 999);
    }

    public function test_search_customer_user_options_empty_search_returns_empty_without_client()
    {
        $this->mock(ZnunyClient::class, function ($mock) {
            $mock->shouldNotReceive('searchCustomerUsers');
        });
        $service = app(ZnunyCachedLookupService::class);
        $this->assertEquals([], $service->searchCustomerUserOptions('   '));
    }

    public function test_search_customer_user_options_empty_labels_fallback_to_login_and_empty_login_skipped()
    {
        $this->mock(ZnunyClient::class, function ($mock) {
            $mock->shouldReceive('searchCustomerUsers')->once()->andReturn([
                ['login' => 'u1', 'label' => ' '], // empty label
                ['login' => ' ', 'label' => 'L2'], // empty login
                ['login' => 'u3', 'label' => null], // absent label
                ['label' => 'L4'], // absent login
                ['login' => 'u5', 'label' => 'L5'],
            ]);
        });
        $service = app(ZnunyCachedLookupService::class);
        $options = $service->searchCustomerUserOptions('search');

        $this->assertEquals([
            'u1' => 'u1',
            'u3' => 'u3',
            'u5' => 'L5',
        ], $options);

        // ensure order is preserved
        $this->assertEquals(['u1', 'u3', 'u5'], array_keys($options));
    }

    public function test_search_customer_user_options_exception_returns_empty()
    {
        $this->mock(ZnunyClient::class, function ($mock) {
            $mock->shouldReceive('searchCustomerUsers')->once()->andThrow(new \Exception('client err'));
        });
        $service = app(ZnunyCachedLookupService::class);
        $this->assertEquals([], $service->searchCustomerUserOptions('search'));
    }

    public function test_search_customer_user_options_repeated_calls_prove_no_caching()
    {
        $this->mock(ZnunyClient::class, function ($mock) {
            $mock->shouldReceive('searchCustomerUsers')->twice()->andReturn([]);
        });
        $service = app(ZnunyCachedLookupService::class);
        $service->searchCustomerUserOptions('search');
        $service->searchCustomerUserOptions('search');
    }

    public function test_search_customer_user_options_accepts_stringable_objects()
    {
        $this->mock(ZnunyClient::class, function ($mock) {
            $mock->shouldNotReceive('getCustomerUser');
            $mock->shouldReceive('searchCustomerUsers')->once()->andReturn([
                [
                    'login' => new class implements \Stringable
                    {
                        public function __toString(): string
                        {
                            return ' obj-login ';
                        }
                    },
                    'label' => new class implements \Stringable
                    {
                        public function __toString(): string
                        {
                            return ' obj-label ';
                        }
                    },
                ],
            ]);
        });
        $service = app(ZnunyCachedLookupService::class);
        $options = $service->searchCustomerUserOptions('search');

        $this->assertEquals(['obj-login' => 'obj-label'], $options);
    }

    public function test_get_prewarm_dataset_state_ready_and_valid()
    {
        $this->mock(ZnunyClient::class, function ($mock) {
            $mock->shouldNotReceive('getCustomerUser');
            $mock->shouldNotReceive('searchCustomerUsers');
        });

        $this->mock(ZnunyQueueCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getSnapshot')->once()->andReturn([]);
            $mock->shouldReceive('getMetadata')->once()->andReturn(['status' => 'ready']);
        });

        $service = app(ZnunyCachedLookupService::class);
        $state = $service->getPrewarmDatasetState('queues');
        $this->assertEquals(['available' => true, 'status' => 'ready'], $state);
    }

    public function test_get_prewarm_dataset_state_stale_and_valid()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getSnapshot')->once()->andReturn([]);
            $mock->shouldReceive('getMetadata')->once()->andReturn(['status' => 'stale']);
        });

        $service = app(ZnunyCachedLookupService::class);
        $state = $service->getPrewarmDatasetState('queues');
        $this->assertEquals(['available' => true, 'status' => 'stale'], $state);
    }

    public function test_get_prewarm_dataset_state_refreshing_and_valid()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getSnapshot')->once()->andReturn([]);
            $mock->shouldReceive('getMetadata')->once()->andReturn(['status' => 'refreshing']);
        });

        $service = app(ZnunyCachedLookupService::class);
        $state = $service->getPrewarmDatasetState('queues');
        $this->assertEquals(['available' => true, 'status' => 'refreshing'], $state);
    }

    public function test_get_prewarm_dataset_state_missing_and_null_snapshot()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getSnapshot')->once()->andReturnNull();
            $mock->shouldReceive('getMetadata')->once()->andReturn(['status' => 'missing']);
        });

        $service = app(ZnunyCachedLookupService::class);
        $state = $service->getPrewarmDatasetState('queues');
        $this->assertEquals(['available' => false, 'status' => 'missing'], $state);
    }

    public function test_get_prewarm_dataset_state_failed_and_null_snapshot()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getSnapshot')->once()->andReturnNull();
            $mock->shouldReceive('getMetadata')->once()->andReturn(['status' => 'failed']);
        });

        $service = app(ZnunyCachedLookupService::class);
        $state = $service->getPrewarmDatasetState('queues');
        $this->assertEquals(['available' => false, 'status' => 'failed'], $state);
    }

    public function test_get_prewarm_dataset_state_ready_metadata_but_null_snapshot()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getSnapshot')->once()->andReturnNull();
            $mock->shouldReceive('getMetadata')->once()->andReturn(['status' => 'ready']);
        });

        $service = app(ZnunyCachedLookupService::class);
        $state = $service->getPrewarmDatasetState('queues');
        $this->assertEquals(['available' => false, 'status' => 'ready'], $state);
    }

    public function test_get_prewarm_dataset_state_invalid_or_missing_status()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getSnapshot')->twice()->andReturn([]);
            $mock->shouldReceive('getMetadata')->once()->andReturn([]);
            $mock->shouldReceive('getMetadata')->once()->andReturn(['status' => 'foo']);
        });

        $service = app(ZnunyCachedLookupService::class);

        $state1 = $service->getPrewarmDatasetState('queues');
        $this->assertEquals(['available' => true, 'status' => 'unknown'], $state1);

        $state2 = $service->getPrewarmDatasetState('queues');
        $this->assertEquals(['available' => true, 'status' => 'unknown'], $state2);
    }

    public function test_get_prewarm_dataset_state_reader_exception()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getSnapshot')->once()->andThrow(new \Exception('Reader err'));
        });

        $service = app(ZnunyCachedLookupService::class);
        $state = $service->getPrewarmDatasetState('queues');
        $this->assertEquals(['available' => false, 'status' => 'failed'], $state);
    }

    public function test_get_prewarm_dataset_state_invalid_dataset()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function ($mock) {
            $mock->shouldNotReceive('getSnapshot');
            $mock->shouldNotReceive('getMetadata');
        });
        $this->mock(ZnunyAgentCacheReadService::class, function ($mock) {
            $mock->shouldNotReceive('getSnapshot');
            $mock->shouldNotReceive('getMetadata');
        });
        $this->mock(ZnunyLookupCacheReadService::class, function ($mock) {
            $mock->shouldNotReceive('getSnapshot');
            $mock->shouldNotReceive('getMetadata');
        });
        $this->mock(ZnunyCustomerUserCacheReadService::class, function ($mock) {
            $mock->shouldNotReceive('getSnapshot');
            $mock->shouldNotReceive('getMetadata');
        });

        $service = app(ZnunyCachedLookupService::class);
        $state = $service->getPrewarmDatasetState('invalid_dataset');
        $this->assertEquals(['available' => false, 'status' => 'unknown'], $state);
    }

    public function test_get_prewarm_dataset_state_mapping_coverage()
    {
        $this->mock(ZnunyQueueCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getSnapshot')->once()->andReturn([]);
            $mock->shouldReceive('getMetadata')->once()->andReturn(['status' => 'ready']);
        });
        $this->mock(ZnunyAgentCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getSnapshot')->once()->andReturn([]);
            $mock->shouldReceive('getMetadata')->once()->andReturn(['status' => 'stale']);
        });
        $this->mock(ZnunyLookupCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getSnapshot')->once()->andReturn([]);
            $mock->shouldReceive('getMetadata')->once()->andReturn(['status' => 'refreshing']);
        });
        $this->mock(ZnunyCustomerUserCacheReadService::class, function ($mock) {
            $mock->shouldReceive('getSnapshot')->once()->andReturn([]);
            $mock->shouldReceive('getMetadata')->once()->andReturn(['status' => 'missing']);
        });

        $service = app(ZnunyCachedLookupService::class);
        $this->assertEquals(['available' => true, 'status' => 'ready'], $service->getPrewarmDatasetState('queues'));
        $this->assertEquals(['available' => true, 'status' => 'stale'], $service->getPrewarmDatasetState('agents'));
        $this->assertEquals(['available' => true, 'status' => 'refreshing'], $service->getPrewarmDatasetState('lookups'));
        $this->assertEquals(['available' => true, 'status' => 'missing'], $service->getPrewarmDatasetState('customer_users'));
    }
}
