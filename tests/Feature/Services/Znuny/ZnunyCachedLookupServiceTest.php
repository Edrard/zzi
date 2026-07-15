<?php

namespace Tests\Feature\Services\Znuny;

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyCachedLookupService;
use App\Services\Znuny\ZnunyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ZnunyCachedLookupServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(SettingsService::class)->clearAllCaches();
        parent::tearDown();
    }

    public function test_caches_all_queues()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->once()->andReturn([
                ['id' => 1, 'name' => 'Raw', 'label' => 'Raw'],
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);

        $queues1 = $service->getAllQueues();
        $this->assertCount(1, $queues1);

        // Second call should hit cache, not the mock (since we used once())
        $queues2 = $service->getAllQueues();
        $this->assertCount(1, $queues2);
    }

    public function test_filters_queues_with_regexes()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
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

    public function test_resolves_customer_search_terms_with_mappings()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn([
                ['id' => 1, 'name' => 'NetworkQueue', 'label' => 'Network Queue (Full)'],
            ]);
        });

        Setting::updateOrCreate(
            ['key' => 'znuny_queue_host_mappings'],
            [
                'type' => 'json',
                'value' => json_encode([
                    ['queue_name' => 'NetworkQueue', 'host_prefix' => 'Net1'],
                    ['queue_name' => 'NetworkQueue', 'host_prefix' => 'Net2'],
                ]),
            ]
        );

        $service = app(ZnunyCachedLookupService::class);
        $terms = $service->getCustomerUserSearchTerms('NetworkQueue');

        // Mapped prefixes first, then name, then label
        $this->assertEquals(['Net1', 'Net2', 'NetworkQueue', 'Network Queue (Full)'], $terms);
    }

    public function test_caches_owner_options_for_queue()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->andReturn([
                ['id' => 1, 'name' => 'Raw', 'label' => 'Raw'],
            ]);
            $mock->shouldReceive('getQueueAssignableAgents')->with(1)->once()->andReturn([
                ['id' => 10, 'label' => 'John Doe'],
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);

        $owners1 = $service->getAssignableOwnerOptionsForQueue('Raw');
        $this->assertEquals([10 => 'John Doe'], $owners1);

        // Second call should hit cache
        $owners2 = $service->getAssignableOwnerOptionsForQueue('Raw');
        $this->assertEquals([10 => 'John Doe'], $owners2);
    }

    public function test_clear_cache_invalidates_version()
    {
        $service = app(ZnunyCachedLookupService::class);
        $v1 = $service->getCacheVersion();

        $service->invalidateCache();
        $v2 = $service->getCacheVersion();

        $service->invalidateCache();
        $v3 = $service->getCacheVersion();

        $this->assertGreaterThan($v1, $v2);
        $this->assertGreaterThan($v2, $v3);
    }

    public function test_clear_cache_forces_next_lookup_to_call_client()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->once()->andReturn([
                ['id' => 1, 'name' => 'First', 'label' => 'First'],
            ]);
            $mock->shouldReceive('getQueues')->once()->andReturn([
                ['id' => 1, 'name' => 'Second', 'label' => 'Second'],
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);
        $result1 = $service->getAllQueues();
        $this->assertEquals('First', $result1[0]['name']);

        $service->clearCache();

        $result2 = $service->getAllQueues();
        $this->assertEquals('Second', $result2[0]['name']);
    }

    public function test_exception_does_not_cache_empty_array()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->once()->andThrow(new \Exception('API Error'));
            $mock->shouldReceive('getQueues')->once()->andReturn([
                ['id' => 1, 'name' => 'Raw', 'label' => 'Raw'],
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);

        // First call fails, returns []
        $queues1 = $service->getAllQueues();
        $this->assertEquals([], $queues1);

        // Second call should retry client and succeed, not hit cache
        $queues2 = $service->getAllQueues();
        $this->assertEquals([['id' => 1, 'name' => 'Raw', 'label' => 'Raw']], $queues2);
    }

    public function test_customer_user_label_caches_successful_result()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getCustomerUser')->with('knownuser')->once()->andReturn([
                'found' => true,
                'login' => 'knownuser',
                'label' => 'Known User <knownuser>',
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);

        $label1 = $service->getCustomerUserLabel('knownuser');
        $this->assertEquals('Known User <knownuser>', $label1);

        // Second call should hit cache, not the mock
        $label2 = $service->getCustomerUserLabel('knownuser');
        $this->assertEquals('Known User <knownuser>', $label2);
    }

    public function test_customer_user_label_does_not_cache_missing_user()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getCustomerUser')->with('missinguser')->twice()->andReturn([
                'found' => false,
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);

        $label1 = $service->getCustomerUserLabel('missinguser');
        $this->assertNull($label1);

        // Second call should hit the mock again, as negative results are not cached
        $label2 = $service->getCustomerUserLabel('missinguser');
        $this->assertNull($label2);
    }

    public function test_customer_user_label_does_not_cache_exception()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getCustomerUser')->with('erroruser')->twice()->andThrow(new \Exception('API Error'));
        });

        $service = app(ZnunyCachedLookupService::class);

        $label1 = $service->getCustomerUserLabel('erroruser');
        $this->assertNull($label1);

        // Second call should hit the mock again, as exceptions are not cached
        $label2 = $service->getCustomerUserLabel('erroruser');
        $this->assertNull($label2);
    }

    public function test_get_customer_user_search_terms_uses_cached_queues()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->once()->andReturn([
                ['id' => 1, 'name' => 'NetworkQueue', 'label' => 'Network Queue (Full)'],
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);

        // First call populates cache via getAllQueues()
        $terms1 = $service->getCustomerUserSearchTerms('NetworkQueue');

        // Second call should hit cache for getAllQueues() and not call client again
        $terms2 = $service->getCustomerUserSearchTerms('NetworkQueue');

        $this->assertEquals(['NetworkQueue', 'Network Queue (Full)'], $terms1);
        $this->assertEquals(['NetworkQueue', 'Network Queue (Full)'], $terms2);
    }

    public function test_get_ticket_states_handles_string_list()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getTicketStates')->once()->andReturn([
                'open',
                'closed successful',
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);
        $states = $service->getTicketStates();

        $this->assertEquals([
            'open' => 'open',
            'closed successful' => 'closed successful',
        ], $states);
    }

    public function test_get_ticket_states_handles_array_objects()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getTicketStates')->once()->andReturn([
                ['name' => 'open'],
                ['Name' => 'closed successful'],
                ['label' => 'pending reminder'],
                ['id' => 4, 'value' => 'merged'],
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);
        $states = $service->getTicketStates();

        $this->assertEquals([
            'open' => 'open',
            'closed successful' => 'closed successful',
            'pending reminder' => 'pending reminder',
            'merged' => 'merged',
        ], $states);
    }

    public function test_get_ticket_types_handles_array_objects_and_skips_malformed()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getTicketTypes')->once()->andReturn([
                ['name' => 'Unclassified'],
                ['id' => 2], // malformed, missing name key
                ['Name' => 'Incident'],
                '', // malformed empty string
                null, // malformed null
                ['name' => ''], // empty string value
                ['Label' => 'RfC'],
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);
        $types = $service->getTicketTypes();

        $this->assertEquals([
            'Unclassified' => 'Unclassified',
            'Incident' => 'Incident',
            'RfC' => 'RfC',
        ], $types);
    }

    public function test_get_ticket_priorities_handles_nested_data_key()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getTicketPriorities')->once()->andReturn([
                'Data' => [
                    ['name' => '1 very low'],
                    ['name' => '2 low'],
                    ['name' => '3 normal'],
                ],
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);
        $priorities = $service->getTicketPriorities();

        $this->assertEquals([
            '1 very low' => '1 very low',
            '2 low' => '2 low',
            '3 normal' => '3 normal',
        ], $priorities);
    }

    public function test_dictionary_methods_cache_successful_result_and_retry_on_exception()
    {
        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getTicketStates')->once()->andReturn([]);
            $mock->shouldReceive('getTicketStates')->once()->andReturn(['open']);
            $mock->shouldReceive('getTicketStates')->never();
        });

        $service = app(ZnunyCachedLookupService::class);

        // First call fails validation (empty array)
        $states1 = $service->getTicketStates();
        $this->assertEquals([], $states1);

        // Second call retries and caches successful result
        $states2 = $service->getTicketStates();
        $this->assertEquals(['open' => 'open'], $states2);

        // Third call hits cache, not the mock
        $states3 = $service->getTicketStates();
        $this->assertEquals(['open' => 'open'], $states3);
    }

    public function test_positive_ttl_caches_and_expires_exactly()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_lookup_cache_ttl_minutes'],
            ['type' => 'integer', 'value' => '10']
        );

        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->once()->andReturn([
                ['id' => 1, 'name' => 'DatasetA', 'label' => 'DatasetA'],
            ]);
            $mock->shouldReceive('getQueues')->once()->andReturn([
                ['id' => 2, 'name' => 'DatasetB', 'label' => 'DatasetB'],
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);

        // First call populates cache
        $result1 = $service->getAllQueues();
        $this->assertEquals('DatasetA', $result1[0]['name']);

        // Travel 9 minutes - still cached
        try {
            $this->travel(9)->minutes();
            $result2 = $service->getAllQueues();
            $this->assertEquals('DatasetA', $result2[0]['name']);

            // Travel to > 10 minutes - expires, fetches DatasetB
            $this->travel(2)->minutes(); // total 11
            $result3 = $service->getAllQueues();
            $this->assertEquals('DatasetB', $result3[0]['name']);
        } finally {
            $this->travelBack();
        }
    }

    public function test_zero_ttl_bypasses_cache()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_lookup_cache_ttl_minutes'],
            ['type' => 'integer', 'value' => '0']
        );

        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->times(3)->andReturn([
                ['id' => 1, 'name' => 'Live', 'label' => 'Live'],
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);

        $service->getAllQueues();
        $service->getAllQueues();
        $service->getAllQueues();

        $key = 'znuny_lookup_queues_all_v'.$service->getCacheVersion();
        $this->assertFalse(Cache::has($key));
    }

    public function test_zero_ttl_bypasses_cache_for_customer_user_label()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_lookup_cache_ttl_minutes'],
            ['type' => 'integer', 'value' => '0']
        );

        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getCustomerUser')->with('testuser')->twice()->andReturn([
                'found' => true,
                'login' => 'testuser',
                'label' => 'Test User',
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);

        $result1 = $service->getCustomerUserLabel('testuser');
        $this->assertEquals('Test User', $result1);

        $result2 = $service->getCustomerUserLabel('testuser');
        $this->assertEquals('Test User', $result2);

        $key = 'znuny_lookup_customer_label_'.md5('testuser').'_v'.$service->getCacheVersion();
        $this->assertFalse(Cache::has($key));
    }

    #[DataProvider('invalidTtlProvider')]
    public function test_invalid_ttl_falls_back_to_60_minutes($value, $type)
    {
        if ($value !== null) {
            Setting::updateOrCreate(
                ['key' => 'znuny_lookup_cache_ttl_minutes'],
                ['type' => $type, 'value' => $value]
            );
        } else {
            Setting::where('key', 'znuny_lookup_cache_ttl_minutes')->delete();
        }

        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueues')->once()->andReturn([
                ['id' => 1, 'name' => 'Fallback', 'label' => 'Fallback'],
            ]);
            $mock->shouldReceive('getQueues')->once()->andReturn([
                ['id' => 2, 'name' => 'Expired', 'label' => 'Expired'],
            ]);
        });

        $service = app(ZnunyCachedLookupService::class);

        $service->getAllQueues();

        try {
            $this->travel(59)->minutes();
            $service->getAllQueues(); // Still cached at 59 mins

            $this->travel(2)->minutes(); // 61 mins
            $service->getAllQueues(); // Expired at 61 mins
        } finally {
            $this->travelBack();
        }
    }

    public static function invalidTtlProvider(): array
    {
        return [
            'missing setting' => [null, 'integer'],
            'unreadable string' => ['invalid', 'string'],
            'negative value' => [-15, 'integer'],
        ];
    }
}
