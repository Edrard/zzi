<?php

namespace Tests\Feature\Znuny\Cache;

use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZnunyWarmLookupsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
        app(\App\Services\SettingsService::class)->clearRequestCache();
        Cache::put('app_settings_all', [
            'znuny_api_url' => ['key' => 'znuny_api_url', 'value' => 'http://test', 'type' => 'string'],
            'znuny_username' => ['key' => 'znuny_username', 'value' => 'u', 'type' => 'string'],
            'znuny_password' => ['key' => 'znuny_password', 'value' => 'p', 'type' => 'string'],
        ]);
    }

    public function test_successful_atomic_publication()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/TicketState*' => Http::response([
                'TicketStates' => [
                    'scalar open',
                    ['name' => 'closed'],
                    ['Label' => 'merged'],
                ]
            ]),
            '*/TicketPriority*' => Http::response([
                'TicketPriorities' => [
                    'Data' => [
                        ['value' => 'low'],
                        ['name' => 'high'],
                    ]
                ]
            ]),
            '*/TicketType*' => Http::response([
                'TicketTypes' => [
                    ['Name' => 'Incident'],
                    ['Label' => 'RfC'],
                    ['Value' => 'Problem'],
                ]
            ]),
        ]);

        $this->artisan('znuny:cache:warm-lookups')->assertSuccessful();

        $meta = Cache::get('znuny_prewarm_lookups_meta');
        $this->assertEquals('ready', $meta['status']);
        $this->assertNotEmpty($meta['active_generation']);
        $this->assertEquals(8, $meta['item_count']);
        $this->assertEquals('artisan', $meta['refresh_source']);

        $payload = Cache::get($meta['active_generation']);

        $expectedStates = [
            'closed' => 'closed',
            'merged' => 'merged',
            'scalar open' => 'scalar open',
        ];
        $this->assertEquals($expectedStates, $payload['states']);

        $expectedPriorities = [
            'high' => 'high',
            'low' => 'low',
        ];
        $this->assertEquals($expectedPriorities, $payload['priorities']);

        $expectedTypes = [
            'Incident' => 'Incident',
            'Problem' => 'Problem',
            'RfC' => 'RfC',
        ];
        $this->assertEquals($expectedTypes, $payload['types']);

        Http::assertSentCount(4);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/Session'));
        Http::assertSent(fn ($req) => str_contains($req->url(), '/TicketState'));
        Http::assertSent(fn ($req) => str_contains($req->url(), '/TicketPriority'));
        Http::assertSent(fn ($req) => str_contains($req->url(), '/TicketType'));
    }

    public function test_scalar_numeric_item_is_normalized_to_trimmed_string_and_readable()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/TicketState*' => Http::response(['TicketStates' => [123, 45.6]]),
            '*/TicketPriority*' => Http::response(['TicketPriorities' => [['name' => 'high']]]),
            '*/TicketType*' => Http::response(['TicketTypes' => [['name' => 'Incident']]]),
        ]);

        $this->artisan('znuny:cache:warm-lookups')->assertSuccessful();
        $meta = Cache::get('znuny_prewarm_lookups_meta');
        $payload = Cache::get($meta['active_generation']);

        $expectedStates = [
            '123' => '123',
            '45.6' => '45.6',
        ];
        $this->assertEquals($expectedStates, $payload['states']);

        // Prove reader can read it
        $reader = new ZnunyLookupCacheReadService();
        $snapshot = $reader->getSnapshot();
        $this->assertNotNull($snapshot);
        $this->assertEquals($expectedStates, $snapshot['states']);
    }

    public function test_natural_sorting_distinguishes_lexical_from_natural_with_exact_secondary_tie_breaker()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/TicketState*' => Http::response(['TicketStates' => ['open']]),
            '*/TicketPriority*' => Http::response(['TicketPriorities' => [
                '10 high',
                '2 low',
                '1 very low',
                '10 High', // exact secondary tiebreaker (case-sensitive) tests stability
            ]]),
            '*/TicketType*' => Http::response(['TicketTypes' => ['Incident']]),
        ]);

        $this->artisan('znuny:cache:warm-lookups')->assertSuccessful();
        $meta = Cache::get('znuny_prewarm_lookups_meta');
        $payload = Cache::get($meta['active_generation']);

        // Natural case-insensitive first, then exact case-sensitive
        // "1 very low", "2 low", "10 High", "10 high"
        $expectedPriorities = [
            '1 very low' => '1 very low',
            '2 low' => '2 low',
            '10 High' => '10 High',
            '10 high' => '10 high',
        ];

        // array_keys preserves exact insertion order from our sorted assoc array
        $this->assertEquals(array_keys($expectedPriorities), array_keys($payload['priorities']));
    }

    public function test_empty_category_fails()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/TicketState*' => Http::response(['TicketStates' => []]),
            '*/TicketPriority*' => Http::response(['TicketPriorities' => [['name' => 'high']]]),
            '*/TicketType*' => Http::response(['TicketTypes' => [['name' => 'Incident']]]),
        ]);

        $this->artisan('znuny:cache:warm-lookups')->assertFailed();
    }

    public function test_blank_scalar_fails()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/TicketState*' => Http::response(['TicketStates' => ['   ']]),
            '*/TicketPriority*' => Http::response(['TicketPriorities' => [['name' => 'high']]]),
            '*/TicketType*' => Http::response(['TicketTypes' => [['name' => 'Incident']]]),
        ]);

        $this->artisan('znuny:cache:warm-lookups')->assertFailed();
    }

    public function test_null_item_fails()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/TicketState*' => Http::response(['TicketStates' => [null]]),
            '*/TicketPriority*' => Http::response(['TicketPriorities' => [['name' => 'high']]]),
            '*/TicketType*' => Http::response(['TicketTypes' => [['name' => 'Incident']]]),
        ]);

        $this->artisan('znuny:cache:warm-lookups')->assertFailed();
    }

    public function test_boolean_item_fails()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/TicketState*' => Http::response(['TicketStates' => [true]]),
            '*/TicketPriority*' => Http::response(['TicketPriorities' => [['name' => 'high']]]),
            '*/TicketType*' => Http::response(['TicketTypes' => [['name' => 'Incident']]]),
        ]);

        $this->artisan('znuny:cache:warm-lookups')->assertFailed();
    }

    public function test_malformed_array_without_recognized_value_field_fails()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/TicketState*' => Http::response(['TicketStates' => [['unknown_key' => 'open']]]),
            '*/TicketPriority*' => Http::response(['TicketPriorities' => [['name' => 'high']]]),
            '*/TicketType*' => Http::response(['TicketTypes' => [['name' => 'Incident']]]),
        ]);

        $this->artisan('znuny:cache:warm-lookups')->assertFailed();
    }

    public function test_non_array_top_level_endpoint_category_fails()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/TicketState*' => Http::response(['TicketStates' => 'open']),
            '*/TicketPriority*' => Http::response(['TicketPriorities' => [['name' => 'high']]]),
            '*/TicketType*' => Http::response(['TicketTypes' => [['name' => 'Incident']]]),
        ]);

        $this->artisan('znuny:cache:warm-lookups')->assertFailed();
    }

    public function test_malformed_uppercase_data_wrapper_fails()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/TicketState*' => Http::response(['TicketStates' => ['Data' => 'not an array']]),
            '*/TicketPriority*' => Http::response(['TicketPriorities' => [['name' => 'high']]]),
            '*/TicketType*' => Http::response(['TicketTypes' => [['name' => 'Incident']]]),
        ]);

        $this->artisan('znuny:cache:warm-lookups')->assertFailed();
    }

    public function test_malformed_lowercase_data_wrapper_fails()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/TicketState*' => Http::response(['TicketStates' => ['data' => 'not an array']]),
            '*/TicketPriority*' => Http::response(['TicketPriorities' => [['name' => 'high']]]),
            '*/TicketType*' => Http::response(['TicketTypes' => [['name' => 'Incident']]]),
        ]);

        $this->artisan('znuny:cache:warm-lookups')->assertFailed();
    }

    public function test_duplicate_normalized_value_fails()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/TicketState*' => Http::response(['TicketStates' => ['open', ' open ']]),
            '*/TicketPriority*' => Http::response(['TicketPriorities' => [['name' => 'high']]]),
            '*/TicketType*' => Http::response(['TicketTypes' => [['name' => 'Incident']]]),
        ]);

        $this->artisan('znuny:cache:warm-lookups')->assertFailed();
    }

    public function test_failure_in_subsequent_category_publishes_none()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/TicketState*' => Http::response(['TicketStates' => ['open']]),
            '*/TicketPriority*' => Http::response(['TicketPriorities' => ['high']]),
            '*/TicketType*' => Http::response(['TicketTypes' => []]),
        ]);

        $this->artisan('znuny:cache:warm-lookups')->assertFailed();
        $meta = Cache::get('znuny_prewarm_lookups_meta');
        $this->assertEquals('failed', $meta['status']);
        $this->assertNull($meta['active_generation']);
    }

    public function test_existing_old_snapshot_remains_active_and_metadata_becomes_stale_on_failure()
    {
        Cache::put('znuny_prewarm_lookups_meta', [
            'active_generation' => 'gen_old',
            'status' => 'ready',
        ], now()->addMinutes(10));

        $oldPayload = [
            'states' => ['open' => 'open'],
            'priorities' => ['high' => 'high'],
            'types' => ['incident' => 'incident'],
        ];
        Cache::put('gen_old', $oldPayload, now()->addMinutes(10));

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/TicketState*' => Http::response(['TicketStates' => ['open']]),
            '*/TicketPriority*' => Http::response(['TicketPriorities' => ['high']]),
            '*/TicketType*' => Http::response(['TicketTypes' => []]), // fails
        ]);

        $this->artisan('znuny:cache:warm-lookups')->assertFailed();

        $meta = Cache::get('znuny_prewarm_lookups_meta');
        $this->assertEquals('stale', $meta['status']);
        $this->assertEquals('gen_old', $meta['active_generation']);
        $this->assertEquals('artisan', $meta['refresh_source']);
        $this->assertNotEmpty($meta['last_error']);

        $payload = Cache::get('gen_old');
        // Assert strict equality of old payload remains
        $this->assertSame($oldPayload, $payload);
    }

    public function test_sanitized_failure_result_preserves_error_without_secret_leak()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/TicketState*' => Http::response(['TicketStates' => ['password=stage3a-secret', 'password=stage3a-secret']]),
            '*/TicketPriority*' => Http::response(['TicketPriorities' => ['high']]),
            '*/TicketType*' => Http::response(['TicketTypes' => ['Incident']]),
        ]);

        $exitCode = \Illuminate\Support\Facades\Artisan::call('znuny:cache:warm-lookups');
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertEquals(\Illuminate\Console\Command::FAILURE, $exitCode);

        $meta = Cache::get('znuny_prewarm_lookups_meta');
        $this->assertNotEmpty($meta['last_error']);
        $this->assertStringNotContainsString('stage3a-secret', $meta['last_error']);
        $this->assertStringNotContainsString('password=stage3a-secret', $meta['last_error']);
        $this->assertStringContainsString('***', $meta['last_error']);
        $this->assertEquals('artisan', $meta['refresh_source']);

        $this->assertStringNotContainsString('stage3a-secret', $output);
        $this->assertStringNotContainsString('password=stage3a-secret', $output);
        $this->assertStringContainsString('***', $output);
    }

    public function test_lock_contention_returns_success_without_http_publication()
    {
        $lock = Cache::lock('znuny_prewarm_lookups_lock', 660);
        $this->assertTrue($lock->acquire());

        try {
            Http::fake();

            $this->artisan('znuny:cache:warm-lookups')->assertSuccessful();

            Http::assertNothingSent();
            $meta = Cache::get('znuny_prewarm_lookups_meta');
            $this->assertNull($meta);
        } finally {
            $lock->release();
        }
    }

    public function test_no_unrelated_lookup_endpoint_is_called()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/TicketState*' => Http::response(['TicketStates' => ['open']]),
            '*/TicketPriority*' => Http::response(['TicketPriorities' => ['high']]),
            '*/TicketType*' => Http::response(['TicketTypes' => ['Incident']]),
            '*' => Http::response('unrelated', 500),
        ]);

        $this->artisan('znuny:cache:warm-lookups')->assertSuccessful();
        Http::assertSentCount(4);
    }
}
