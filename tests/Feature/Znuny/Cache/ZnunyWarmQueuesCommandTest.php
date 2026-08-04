<?php

namespace Tests\Feature\Znuny\Cache;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZnunyWarmQueuesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::clear();
        \App\Services\SettingsService::clearRequestCache();
        \Illuminate\Support\Facades\Cache::put('app_settings_all', [
            'znuny_api_url' => ['key' => 'znuny_api_url', 'value' => 'http://test', 'type' => 'string'],
            'znuny_username' => ['key' => 'znuny_username', 'value' => 'u', 'type' => 'string'],
            'znuny_password' => ['key' => 'znuny_password', 'value' => 'p', 'type' => 'string'],
        ]);
    }

    public function test_successful_warmup()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/Queue*' => Http::response([
                'Queues' => [
                    ['QueueID' => '1', 'Name' => 'Support', 'ValidID' => '1'],
                    ['QueueID' => 2, 'Name' => 'IT', 'ValidID' => '1'],
                ]
            ])
        ]);

        $this->artisan('znuny:cache:warm-queues')
             ->assertSuccessful();

        $meta = \Illuminate\Support\Facades\Cache::get('znuny_prewarm_queues_meta');
        $this->assertIsArray($meta);
        $this->assertEquals('ready', $meta['status']);
        $this->assertNotEmpty($meta['active_generation']);
        $this->assertIsString($meta['active_generation']);
        $this->assertEquals(2, $meta['item_count']);

        $payload = \Illuminate\Support\Facades\Cache::get($meta['active_generation']);
        $this->assertIsArray($payload);
        $this->assertCount(2, $payload);

        $this->assertEquals('IT', $payload[0]['name']);
        $this->assertEquals(2, $payload[0]['id']);

        $this->assertEquals('Support', $payload[1]['name']);
        $this->assertEquals(1, $payload[1]['id']);
    }

    public function test_decimal_queue_id_rejected()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/Queue*' => Http::response(['Queues' => [['QueueID' => 1.5, 'Name' => 'Support', 'ValidID' => 1]]])
        ]);

        $this->artisan('znuny:cache:warm-queues')->assertFailed();
    }

    public function test_exponent_queue_id_rejected()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/Queue*' => Http::response(['Queues' => [['QueueID' => '1e3', 'Name' => 'Support', 'ValidID' => 1]]])
        ]);

        $this->artisan('znuny:cache:warm-queues')->assertFailed();
    }

    public function test_leading_zero_queue_id_rejected()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/Queue*' => Http::response(['Queues' => [['QueueID' => '01', 'Name' => 'Support', 'ValidID' => 1]]])
        ]);

        $this->artisan('znuny:cache:warm-queues')->assertFailed();
    }

    public function test_missing_valid_id_fails()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/Queue*' => Http::response(['Queues' => [['QueueID' => 1, 'Name' => 'Support']]])
        ]);

        $this->artisan('znuny:cache:warm-queues')->assertFailed();
    }

    public function test_malformed_valid_id_fails()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/Queue*' => Http::response(['Queues' => [['QueueID' => 1, 'Name' => 'Support', 'ValidID' => -5]]])
        ]);

        $this->artisan('znuny:cache:warm-queues')->assertFailed();
    }

    public function test_mixed_valid_and_malformed_queue_fails_and_preserves_old_snapshot()
    {
        \Illuminate\Support\Facades\Cache::forever('znuny_prewarm_queues_meta', [
            'active_generation' => 'old_gen_1',
            'status' => 'ready'
        ]);
        \Illuminate\Support\Facades\Cache::forever('old_gen_1', [
            ['id' => 1, 'name' => 'OldQueue', 'label' => 'OldQueue']
        ]);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/Queue*' => Http::response([
                'Queues' => [
                    ['QueueID' => 2, 'Name' => 'ValidQueue', 'ValidID' => 1],
                    ['QueueID' => '01', 'Name' => 'MalformedQueue', 'ValidID' => 1] // Malformed
                ]
            ])
        ]);

        $this->artisan('znuny:cache:warm-queues')->assertFailed();

        $meta = \Illuminate\Support\Facades\Cache::get('znuny_prewarm_queues_meta');
        $this->assertEquals('stale', $meta['status']);
        $this->assertEquals('old_gen_1', $meta['active_generation']);
        $this->assertEquals([
            ['id' => 1, 'name' => 'OldQueue', 'label' => 'OldQueue']
        ], \Illuminate\Support\Facades\Cache::get('old_gen_1'));
    }

    public function test_get_queues_valid_data_remains_normalized_correctly()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test'], 200),
            '*/Queue*' => Http::response([
                'Queues' => [
                    ['QueueID' => 5, 'Name' => 'Test Queue', 'FullName' => 'Full Test', 'ValidID' => 1]
                ]
            ], 200)
        ]);

        $client = new \App\Services\Znuny\ZnunyClient();
        $result = $client->getQueues();

        $this->assertCount(1, $result);
        $this->assertEquals(5, $result[0]['id']);
        $this->assertEquals('Test Queue', $result[0]['name']);
        $this->assertEquals('Full Test', $result[0]['full_name']);
        $this->assertEquals(1, $result[0]['valid_id']);
        $this->assertEquals('Test Queue', $result[0]['label']);
    }

    public function test_malformed_scalar_payload_is_rejected()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/Queue*' => Http::response([
                'Queues' => 'scalar_string_instead_of_array'
            ])
        ]);

        $this->artisan('znuny:cache:warm-queues')->assertFailed();
    }

    public function test_missing_snapshot_returns_failed_without_side_effects()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/Queue*' => Http::response([
                'WrongKey' => [] // missing Queues key entirely
            ])
        ]);

        $this->artisan('znuny:cache:warm-queues')->assertFailed();
    }

    public function test_pre_existing_partial_metadata_updates_correctly()
    {
        // Give it some corrupted metadata
        \Illuminate\Support\Facades\Cache::forever('znuny_prewarm_queues_meta', [
            'active_generation' => '',
            'status' => 'weird',
            'item_count' => -10,
        ]);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/Queue*' => Http::response([
                'Queues' => [
                    ['QueueID' => '1', 'Name' => 'Support', 'ValidID' => '1'],
                ]
            ])
        ]);

        $this->artisan('znuny:cache:warm-queues')->assertSuccessful();

        $meta = \Illuminate\Support\Facades\Cache::get('znuny_prewarm_queues_meta');
        $this->assertEquals('ready', $meta['status']);
        $this->assertNotEmpty($meta['active_generation']);
        $this->assertEquals(1, $meta['item_count']);
    }

    public function test_network_exceptions_skip_publication_and_return_failure()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*/Queue*' => function() {
                throw new \Exception('Network error');
            }
        ]);

        $this->artisan('znuny:cache:warm-queues')->assertFailed();

        $meta = \Illuminate\Support\Facades\Cache::get('znuny_prewarm_queues_meta');
        $this->assertEquals('failed', $meta['status']);
        $this->assertStringContainsString('Network error', $meta['last_error']);
    }
}
