<?php

namespace Tests\Feature\Znuny\Cache;

use App\Services\SettingsService;
use App\Services\Znuny\ZnunyClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZnunyWarmCustomerUsersCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
        app(SettingsService::class)->clearRequestCache();
        Cache::put('app_settings_all', [
            'znuny_api_url' => ['key' => 'znuny_api_url', 'value' => 'http://test', 'type' => 'string'],
            'znuny_username' => ['key' => 'znuny_username', 'value' => 'u', 'type' => 'string'],
            'znuny_password' => ['key' => 'znuny_password', 'value' => 'p', 'type' => 'string'],
        ]);
    }

    private function seedValidQueueSnapshot(array $queues)
    {
        Cache::put('znuny_prewarm_queues_meta', [
            'active_generation' => 'q_gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));

        Cache::put('q_gen_1', $queues, now()->addMinutes(10));
    }

    private function appendSettingsMappings(array $mappings)
    {
        $settings = Cache::get('app_settings_all', []);
        $settings['znuny_queue_host_mappings'] = [
            'key' => 'znuny_queue_host_mappings',
            'value' => json_encode($mappings),
            'type' => 'json'
        ];
        Cache::put('app_settings_all', $settings);
    }

    private function rawUser(string $login, string $first, string $last, string $email)
    {
        return [
            'UserLogin' => $login,
            'UserFirstname' => $first,
            'UserLastname' => $last,
            'UserEmail' => $email,
        ];
    }

    public function test_prewarm_search_terms_logic_with_one_word_queue()
    {
        $this->seedValidQueueSnapshot([
            [
                'id' => 1,
                'name' => 'Support',
                'label' => 'Support Label',
                'full_name' => 'Parent::Support',
                'valid_id' => 1,
            ]
        ]);

        $this->appendSettingsMappings([
            ['queue' => 'Support', 'host_prefix' => 'prefix1'],
        ]);

        $sentSearches = [];

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/CustomerUser*' => function ($req) use (&$sentSearches) {
                $search = $req['Search'];
                $sentSearches[] = $search;

                $this->assertEquals('Support', $search);
                return Http::response(['CustomerUsers' => [
                    $this->rawUser('u1', 'First', 'One', 'u1@test'),
                ]]);
            },
        ]);

        $this->artisan('znuny:cache:warm-customer-users')->assertSuccessful();

        $meta = Cache::get('znuny_prewarm_customer_users_meta');
        $payload = Cache::get($meta['active_generation']);
        $queue = $payload['queues'][0];

        $this->assertEquals(['Support'], $queue['search_terms']);
        $this->assertEquals(['Support'], $sentSearches);
    }

    public function test_prewarm_search_terms_logic_with_multi_word_queue()
    {
        $this->seedValidQueueSnapshot([
            [
                'id' => 1,
                'name' => 'Agent bud',
                'label' => 'Agent bud label',
                'full_name' => 'Parent::Agent bud',
                'valid_id' => 1,
            ]
        ]);

        $this->appendSettingsMappings([
            ['queue' => 'Agent bud', 'host_prefix' => 'prefix1'],
        ]);

        $sentSearches = [];

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/CustomerUser*' => function ($req) use (&$sentSearches) {
                $search = $req['Search'];
                $sentSearches[] = $search;

                $this->assertEquals('Agent', $search);
                return Http::response(['CustomerUsers' => [
                    $this->rawUser('AgentBudClients', 'AgentBud', 'Global', 'u1@test'),
                    $this->rawUser('AgentAnother', 'Agent', 'Another', 'u2@test'),
                ]]);
            },
        ]);

        $this->artisan('znuny:cache:warm-customer-users')->assertSuccessful();

        $meta = Cache::get('znuny_prewarm_customer_users_meta');
        $payload = Cache::get($meta['active_generation']);
        $queue = $payload['queues'][0];

        $this->assertEquals(['Agent'], $queue['search_terms']);
        $this->assertEquals(['Agent'], $sentSearches);

        $expectedOptions = [
            'AgentAnother' => 'Agent Another <AgentAnother>',
            'AgentBudClients' => 'AgentBud Global <AgentBudClients>',
        ];

        $this->assertEquals($expectedOptions, $queue['options']);
    }

    public function test_prewarm_search_terms_logic_with_three_words()
    {
        $this->seedValidQueueSnapshot([
            [
                'id' => 1,
                'name' => 'Agent bud ukraine',
                'valid_id' => 1,
            ]
        ]);

        $sentSearches = [];

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/CustomerUser*' => function ($req) use (&$sentSearches) {
                $search = $req['Search'];
                $sentSearches[] = $search;

                $this->assertEquals('Agent', $search);
                return Http::response(['CustomerUsers' => []]);
            },
        ]);

        $this->artisan('znuny:cache:warm-customer-users')->assertSuccessful();

        $meta = Cache::get('znuny_prewarm_customer_users_meta');
        $payload = Cache::get($meta['active_generation']);
        $queue = $payload['queues'][0];

        $this->assertEquals(['Agent'], $queue['search_terms']);
        $this->assertEquals(['Agent'], $sentSearches);
    }

    public function test_prewarm_search_terms_logic_with_short_first_word()
    {
        $this->seedValidQueueSnapshot([
            [
                'id' => 1,
                'name' => 'A Team',
                'valid_id' => 1,
            ]
        ]);

        $sentSearches = [];

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/CustomerUser*' => function ($req) use (&$sentSearches) {
                $search = $req['Search'];
                $sentSearches[] = $search;
                return Http::response(['CustomerUsers' => []]);
            },
        ]);

        $this->artisan('znuny:cache:warm-customer-users')->assertSuccessful();

        $meta = Cache::get('znuny_prewarm_customer_users_meta');
        $payload = Cache::get($meta['active_generation']);
        $queue = $payload['queues'][0];

        $this->assertEquals([], $queue['search_terms']);
        $this->assertEquals([], $sentSearches);
    }

    public function test_fifty_user_cap()
    {
        $this->seedValidQueueSnapshot([
            ['id' => 1, 'name' => 'Q1', 'label' => 'Q1_Label', 'full_name' => 'Q1_Full', 'valid_id' => 1]
        ]);

        $sentSearches = [];

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/CustomerUser*' => function ($req) use (&$sentSearches) {
                $search = $req['Search'];
                $sentSearches[] = $search;

                if ($search === 'Q1') {
                    $res = [];
                    for ($i = 1; $i <= 65; $i++) {
                        $res[] = $this->rawUser("user{$i}", "F{$i}", "L{$i}", "u{$i}@test");
                    }
                    return Http::response(['CustomerUsers' => $res]);
                }
                $this->fail("Should not reach {$search}");
            },
        ]);

        $this->artisan('znuny:cache:warm-customer-users')->assertSuccessful();

        $meta = Cache::get('znuny_prewarm_customer_users_meta');
        $payload = Cache::get($meta['active_generation']);

        $this->assertCount(50, $payload['queues'][0]['options']);
        $this->assertEquals(50, $meta['item_count']);

        $this->assertEquals(['Q1'], $sentSearches);
    }

    public function test_empty_results_are_valid()
    {
        $this->seedValidQueueSnapshot([
            ['id' => 1, 'name' => 'Q1', 'valid_id' => 1]
        ]);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/CustomerUser*' => Http::response(['CustomerUsers' => []]),
        ]);

        $this->artisan('znuny:cache:warm-customer-users')->assertSuccessful();

        $meta = Cache::get('znuny_prewarm_customer_users_meta');
        $payload = Cache::get($meta['active_generation']);

        $this->assertEquals('ready', $meta['status']);
        $this->assertEquals(0, $meta['item_count']);
        $this->assertEquals([], $payload['queues'][0]['options']);
    }

    public function test_item_count_across_queues()
    {
        $this->seedValidQueueSnapshot([
            ['id' => 1, 'name' => 'Q1', 'valid_id' => 1],
            ['id' => 2, 'name' => 'Q2', 'valid_id' => 1],
        ]);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/CustomerUser*' => function ($req) {
                if ($req['Search'] === 'Q1') {
                    return Http::response(['CustomerUsers' => [
                        $this->rawUser('u1', 'A', 'A', 'a@a')
                    ]]);
                }
                if ($req['Search'] === 'Q2') {
                    return Http::response(['CustomerUsers' => [
                        $this->rawUser('u2', 'B', 'B', 'b@b'),
                        $this->rawUser('u3', 'C', 'C', 'c@c'),
                    ]]);
                }
            }
        ]);

        $this->artisan('znuny:cache:warm-customer-users')->assertSuccessful();

        $meta = Cache::get('znuny_prewarm_customer_users_meta');
        $this->assertEquals(3, $meta['item_count']);
    }

    public function test_deterministic_sorting()
    {
        $this->seedValidQueueSnapshot([
            ['id' => 2, 'name' => 'Q2', 'valid_id' => 1],
            ['id' => 1, 'name' => 'Q1', 'valid_id' => 1],
        ]);

        $mockClient = $this->createMock(ZnunyClient::class);
        $mockClient->expects($this->exactly(2))
                   ->method('searchCustomerUsers')
                   ->with($this->anything(), 50)
                   ->willReturnCallback(function ($search, $limit) {
                       if ($search === 'Q1') {
                           return [
                               ['login' => 'natural10', 'label' => 'Item 10'],
                               ['login' => 'natural2', 'label' => 'item 2'],
                               ['login' => 'case-lower', 'label' => 'same'],
                               ['login' => 'case-upper', 'label' => 'Same'],
                               ['login' => 'tie-b', 'label' => 'Tie'],
                               ['login' => 'tie-a', 'label' => 'Tie'],
                           ];
                       }
                       if ($search === 'Q2') {
                           return [];
                       }
                       return [];
                   });

        $this->app->instance(ZnunyClient::class, $mockClient);
        Http::fake();

        $this->artisan('znuny:cache:warm-customer-users')->assertSuccessful();

        $meta = Cache::get('znuny_prewarm_customer_users_meta');
        $payload = Cache::get($meta['active_generation']);

        $this->assertEquals('Q1', $payload['queues'][0]['queue_name']);
        $this->assertEquals('Q2', $payload['queues'][1]['queue_name']);

        $expectedOptionsOrder = [
            'natural2',
            'natural10',
            'case-upper',
            'case-lower',
            'tie-a',
            'tie-b',
        ];

        $this->assertEquals($expectedOptionsOrder, array_keys($payload['queues'][0]['options']));
        Http::assertNothingSent();
    }

    public function test_missing_and_malformed_queue_snapshots_fail_before_http()
    {
        Http::fake();
        $this->artisan('znuny:cache:warm-customer-users')->assertFailed();
        Http::assertNothingSent();

        Cache::put('znuny_prewarm_queues_meta', [
            'active_generation' => 'q_gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));

        Cache::put('q_gen_1', [['malformed' => true]], now()->addMinutes(10));

        $this->artisan('znuny:cache:warm-customer-users')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_malformed_normalized_client_rows_fail_atomically()
    {
        $this->seedValidQueueSnapshot([
            ['id' => 1, 'name' => 'Q1', 'valid_id' => 1],
        ]);

        // Mock ZnunyClient instead of Http::fake because ZnunyClient normalizes raw rows.
        // If we want malformed normalized rows, we must bypass ZnunyClient's normalizer.
        $mockClient = $this->createStub(ZnunyClient::class);
        $mockClient->method('searchCustomerUsers')->willReturn([
            ['login' => 123, 'label' => 'Label'], // login is not a string
        ]);

        $this->app->instance(ZnunyClient::class, $mockClient);
        Http::fake();

        $this->artisan('znuny:cache:warm-customer-users')->assertFailed();

        $meta = Cache::get('znuny_prewarm_customer_users_meta');
        $this->assertEquals('failed', $meta['status']);
        $this->assertNull($meta['active_generation']);
    }

    public function test_failure_on_later_queue_publishes_no_new_generation_and_preserves_old()
    {
        $oldPayload = [
            'queues' => [
                ['queue_id' => 1, 'queue_name' => 'Q1', 'search_terms' => ['Q1'], 'options' => ['u' => 'u']]
            ]
        ];
        Cache::put('znuny_prewarm_customer_users_meta', [
            'active_generation' => 'old_gen',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('old_gen', $oldPayload, now()->addMinutes(10));

        $this->seedValidQueueSnapshot([
            ['id' => 1, 'name' => 'Q1', 'valid_id' => 1],
            ['id' => 2, 'name' => 'Q2', 'valid_id' => 1], // Q2 will fail
        ]);

        $sentSearches = [];
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/CustomerUser*' => function ($req) use (&$sentSearches) {
                $search = $req['Search'];
                $sentSearches[] = $search;
                if ($search === 'Q1') {
                    return Http::response(['CustomerUsers' => []]);
                }
                return Http::response([], 500); // simulate real failure
            }
        ]);

        $this->artisan('znuny:cache:warm-customer-users')->assertFailed();

        $this->assertEquals(['Q1', 'Q2'], $sentSearches);

        $meta = Cache::get('znuny_prewarm_customer_users_meta');
        $this->assertEquals('stale', $meta['status']);
        $this->assertEquals('old_gen', $meta['active_generation']);
        $this->assertNotEmpty($meta['last_error']);
        $this->assertEquals('artisan', $meta['refresh_source']);

        $this->assertSame($oldPayload, Cache::get('old_gen'));
    }

    public function test_lock_contention_succeeds_without_http()
    {
        $lock = Cache::lock('znuny_prewarm_customer_users_lock', 660);
        $this->assertTrue($lock->acquire());

        try {
            Http::fake();
            $this->artisan('znuny:cache:warm-customer-users')->assertSuccessful();
            Http::assertNothingSent();

            $this->assertNull(Cache::get('znuny_prewarm_customer_users_meta'));
        } finally {
            $lock->release();
        }
    }

    public function test_sanitized_failure_output()
    {
        $this->seedValidQueueSnapshot([
            ['id' => 1, 'name' => 'secret_fail', 'valid_id' => 1]
        ]);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/CustomerUser*' => function () {
                throw new \Exception('token=stage3b-secret');
            }
        ]);

        $exitCode = \Illuminate\Support\Facades\Artisan::call('znuny:cache:warm-customer-users');
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertEquals(\Illuminate\Console\Command::FAILURE, $exitCode);
        $this->assertStringNotContainsString('stage3b-secret', $output);
        $this->assertStringContainsString('***', $output);
        $this->assertStringContainsString('token=***', $output);

        $meta = Cache::get('znuny_prewarm_customer_users_meta');
        $this->assertStringNotContainsString('stage3b-secret', $meta['last_error']);
        $this->assertStringContainsString('***', $meta['last_error']);
        $this->assertStringContainsString('token=***', $meta['last_error']);
        $this->assertEquals('artisan', $meta['refresh_source']);
    }
    public function test_customer_user_duplicate_suppression_within_queue()
    {
        $this->seedValidQueueSnapshot([
            ['id' => 1, 'name' => 'Support', 'label' => 'SupportAlias', 'valid_id' => 1]
        ]);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test_dedupe']),
            '*/CustomerUser*' => Http::response([
                'CustomerUsers' => [
                    $this->rawUser('alice.duplicate', 'Alice', 'First', 'first@example.com'),
                    $this->rawUser('alice.duplicate', 'Alice', 'Later', 'later@example.com'), // Duplicate login
                    $this->rawUser('bob.unique', 'Bob', 'Unique', 'bob@example.com')
                ]
            ])
        ]);

        $this->artisan('znuny:cache:warm-customer-users')->assertSuccessful();

        $meta = Cache::get('znuny_prewarm_customer_users_meta');
        $this->assertEquals(2, $meta['item_count'], 'Should count exactly two unique items for the queue');

        $payload = Cache::get($meta['active_generation']);
        $queueOptions = $payload['queues'][0]['options'];

        $this->assertCount(2, $queueOptions, 'Should have exactly two options remaining after deduplication');
        $this->assertArrayHasKey('alice.duplicate', $queueOptions);
        $this->assertArrayHasKey('bob.unique', $queueOptions);

        $this->assertStringContainsString('First', $queueOptions['alice.duplicate'], 'First accepted label should win');
        $this->assertStringNotContainsString('Later', $queueOptions['alice.duplicate'], 'Duplicate result must not replace the first label');
    }

    public function test_sentinel_failed_emits_correct_output()
    {
        $queueService = \Mockery::mock(\App\Services\Znuny\Cache\ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => [['id' => 1, 'name' => 'Support']]]);
        $this->app->instance(\App\Services\Znuny\Cache\ZnunyQueueCacheReadService::class, $queueService);

        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response('Server Error', 500)
        ]);

        $this->artisan('znuny:cache:warm-customer-users')
            ->expectsOutput('PREWARM_RESULT=failed')
            ->assertFailed();
    }

    public function test_sentinel_success_emits_correct_output()
    {
        $queueService = \Mockery::mock(\App\Services\Znuny\Cache\ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => [['id' => 1, 'name' => 'Support']]]);
        $this->app->instance(\App\Services\Znuny\Cache\ZnunyQueueCacheReadService::class, $queueService);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/CustomerUser*' => Http::response([
                'CustomerUsers' => [
                    $this->rawUser('alice', 'A', 'A', 'a@a')
                ]
            ])
        ]);

        $this->artisan('znuny:cache:warm-customer-users')
            ->expectsOutput('PREWARM_RESULT=success')
            ->assertSuccessful();
    }
}
