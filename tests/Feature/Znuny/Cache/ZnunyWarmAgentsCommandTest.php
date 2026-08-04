<?php

namespace Tests\Feature\Znuny\Cache;

use App\Services\Znuny\Cache\ZnunyQueueCacheReadService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Mockery;

class ZnunyWarmAgentsCommandTest extends TestCase
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

    public function test_malformed_queue_snapshot_entry()
    {
        \Illuminate\Support\Facades\Cache::forever('znuny_prewarm_agents_meta', [
            'active_generation' => 'old_gen_1',
            'status' => 'ready'
        ]);
        \Illuminate\Support\Facades\Cache::forever('old_gen_1', [
            'agents' => [['id' => 1]]
        ]);

        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn([
            'generation' => 'queue_gen_1',
            'payload' => [['wrong_key' => 1]]
        ]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        $this->artisan('znuny:cache:warm-agents')->assertFailed();

        $meta = \Illuminate\Support\Facades\Cache::get('znuny_prewarm_agents_meta');
        $this->assertEquals('stale', $meta['status']);
        $this->assertEquals('old_gen_1', $meta['active_generation']);
    }

    public function test_decimal_noncanonical_queue_snapshot_id()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn([
            'generation' => 'queue_gen_1',
            'payload' => [['id' => 1.5]]
        ]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        $this->artisan('znuny:cache:warm-agents')->assertFailed();
    }

    public function test_duplicate_queue_ids()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn([
            'generation' => 'queue_gen_1',
            'payload' => [['id' => 1], ['id' => 1]]
        ]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        $this->artisan('znuny:cache:warm-agents')->assertFailed();
    }

    public function test_empty_agent_list()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => [['id' => 1]]]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        Http::fake([
            '*/Session' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => []])
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertFailed();
    }

    public function test_duplicate_agent_ids()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => [['id' => 1]]]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        Http::fake([
            '*/Session' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => [
                ['UserID' => 1, 'UserLogin' => 'a', 'UserFullname' => 'A'],
                ['UserID' => 1, 'UserLogin' => 'b', 'UserFullname' => 'B'],
            ]])
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertFailed();
    }

    public function test_mixed_valid_and_malformed_agent_fails()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => [['id' => 1]]]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => [
                ['UserID' => 1, 'UserLogin' => 'a', 'UserFullname' => 'A'],
                ['UserID' => 2, 'UserLogin' => ''] // Malformed (empty login)
            ]]),
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertFailed();
    }

    public function test_malformed_user_id_fails()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => [['id' => 1]]]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => [
                ['UserID' => '01', 'UserLogin' => 'a', 'UserFullname' => 'A'], // Leading zero
            ]])
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertFailed();
    }

    public function test_missing_or_empty_user_login_fails()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => [['id' => 1]]]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => [
                ['UserID' => 1, 'UserFullname' => 'A'], // Missing UserLogin
            ]])
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertFailed();
    }

    public function test_missing_queues_in_assignable_queues_response_fails()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => [['id' => 1]]]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => [['UserID' => 1, 'UserLogin' => 'a', 'UserFullname' => 'A']]]),
            '*AssignableQueues*' => Http::response(['WrongKey' => []]),
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertFailed();
    }

    public function test_scalar_queues_fails()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => [['id' => 1]]]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => [['UserID' => 1, 'UserLogin' => 'a', 'UserFullname' => 'A']]]),
            '*AssignableQueues*' => Http::response(['Queues' => 'scalar']),
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertFailed();
    }

    public function test_malformed_relationship_entry_fails()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => [['id' => 1]]]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => [['UserID' => 1, 'UserLogin' => 'a', 'UserFullname' => 'A']]]),
            '*AssignableQueues*' => Http::response(['Queues' => [['WrongKey' => 1]]]),
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertFailed();
    }

    public function test_noncanonical_relationship_queue_id_fails()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => [['id' => 1]]]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => [['UserID' => 1, 'UserLogin' => 'a', 'UserFullname' => 'A']]]),
            '*AssignableQueues*' => Http::response(['Queues' => [['QueueID' => '01']]]),
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertFailed();
    }

    public function test_agent_with_no_queues_remains_present()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => [['id' => 1]]]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => [['UserID' => 1, 'UserLogin' => 'a', 'UserFullname' => 'A']]]),
            '*AssignableQueues*' => Http::response(['Queues' => []]),
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertSuccessful();

        $meta = \Illuminate\Support\Facades\Cache::get('znuny_prewarm_agents_meta');
        $payload = \Illuminate\Support\Facades\Cache::get($meta['active_generation']);

        $this->assertCount(1, $payload['agents']);
        $this->assertEquals(1, $payload['agents'][0]['id']);
        $this->assertEquals([], $payload['agent_to_queues'][1]);
    }

    public function test_queue_with_no_agents_remains_present()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => [['id' => 1], ['id' => 2]]]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => [['UserID' => 1, 'UserLogin' => 'a', 'UserFullname' => 'A']]]),
            '*AssignableQueues*' => Http::response(['Queues' => [['QueueID' => 1]]]),
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertSuccessful();

        $meta = \Illuminate\Support\Facades\Cache::get('znuny_prewarm_agents_meta');
        $payload = \Illuminate\Support\Facades\Cache::get($meta['active_generation']);

        $this->assertArrayHasKey(2, $payload['queue_to_agents']);
        $this->assertEquals([], $payload['queue_to_agents'][2]);
    }

    public function test_same_labels_use_id_tie_breaker()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => [['id' => 1]]]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => [
                ['UserID' => 2, 'UserLogin' => 'same', 'UserFullname' => 'Same'],
                ['UserID' => 1, 'UserLogin' => 'same', 'UserFullname' => 'Same'],
            ]]),
            '*AssignableQueues*' => Http::response(['Queues' => []]),
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertSuccessful();

        $meta = \Illuminate\Support\Facades\Cache::get('znuny_prewarm_agents_meta');
        $activeGen = $meta['active_generation'];
        $payload = \Illuminate\Support\Facades\Cache::get($activeGen);
        $agents = $payload['agents'];

        $this->assertCount(2, $agents);
        $this->assertEquals(1, $agents[0]['id']);
        $this->assertEquals(2, $agents[1]['id']);
    }

    public function test_different_input_order_produces_same_logical_matrix()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn([
            'generation' => 'queue_gen_1',
            'payload' => [['id' => 1], ['id' => 2]]
        ]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        // Run 1
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => [
                ['UserID' => 1, 'UserLogin' => 'a'],
                ['UserID' => 2, 'UserLogin' => 'b'],
            ]]),
            '*/Agent/1/AssignableQueues*' => Http::response(['Queues' => [['QueueID' => 2], ['QueueID' => 1]]]),
            '*/Agent/2/AssignableQueues*' => Http::response(['Queues' => [['QueueID' => 1]]]),
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertSuccessful();
        $meta1 = \Illuminate\Support\Facades\Cache::get('znuny_prewarm_agents_meta');
        $payload1 = \Illuminate\Support\Facades\Cache::get($meta1['active_generation']);

        \Illuminate\Support\Facades\Cache::clear();
        \App\Services\SettingsService::clearRequestCache();
        \Illuminate\Support\Facades\Cache::put('app_settings_all', [
            'znuny_api_url' => ['key' => 'znuny_api_url', 'value' => 'http://test', 'type' => 'string'],
            'znuny_username' => ['key' => 'znuny_username', 'value' => 'u', 'type' => 'string'],
            'znuny_password' => ['key' => 'znuny_password', 'value' => 'p', 'type' => 'string'],
        ]);

        // Run 2: Different order
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => [
                ['UserID' => 2, 'UserLogin' => 'b'],
                ['UserID' => 1, 'UserLogin' => 'a'],
            ]]),
            '*/Agent/2/AssignableQueues*' => Http::response(['Queues' => [['QueueID' => 1]]]),
            '*/Agent/1/AssignableQueues*' => Http::response(['Queues' => [['QueueID' => 1], ['QueueID' => 2]]]),
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertSuccessful();
        $meta2 = \Illuminate\Support\Facades\Cache::get('znuny_prewarm_agents_meta');
        $payload2 = \Illuminate\Support\Facades\Cache::get($meta2['active_generation']);

        $this->assertEquals($payload1, $payload2);
    }

    public function test_queue_to_agents_endpoint_never_called()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => [['id' => 1]]]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => [['UserID' => 1, 'UserLogin' => 'a', 'UserFullname' => 'A']]]),
            '*AssignableQueues*' => Http::response(['Queues' => []]),
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertSuccessful();

        Http::assertNotSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), 'QueueAgent') || str_contains($request->url(), 'AssignableAgents');
        });
    }

    public function test_relationship_fetch_failure_preserves_old_snapshot()
    {
        \Illuminate\Support\Facades\Cache::forever('znuny_prewarm_agents_meta', [
            'active_generation' => 'old_gen_1',
            'status' => 'ready'
        ]);
        \Illuminate\Support\Facades\Cache::forever('old_gen_1', [
            ['id' => 1, 'login' => 'old', 'label' => 'old', 'assignable_queue_ids' => [1]]
        ]);

        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn(['generation' => 'queue_gen_1', 'payload' => [['id' => 1]]]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => [
                ['UserID' => 1, 'UserLogin' => 'a', 'UserFullname' => 'a'],
                ['UserID' => 2, 'UserLogin' => 'b', 'UserFullname' => 'b'],
            ]]),
            '*/Agent/1/AssignableQueues*' => Http::response(['Queues' => [['QueueID' => 1]]]),
            '*/Agent/2/AssignableQueues*' => function () {
                throw new \Exception('Simulated fetch failure');
            }
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertFailed();

        $meta = \Illuminate\Support\Facades\Cache::get('znuny_prewarm_agents_meta');
        $this->assertEquals('stale', $meta['status']);
        $this->assertEquals('old_gen_1', $meta['active_generation']);
        $this->assertEquals([
            ['id' => 1, 'login' => 'old', 'label' => 'old', 'assignable_queue_ids' => [1]]
        ], \Illuminate\Support\Facades\Cache::get('old_gen_1'));
    }

    public function test_queue_generation_changing_during_warmup_aborts_publication()
    {
        \Illuminate\Support\Facades\Cache::forever('znuny_prewarm_agents_meta', [
            'active_generation' => 'old_agent_gen',
            'status' => 'ready'
        ]);
        \Illuminate\Support\Facades\Cache::forever('old_agent_gen', [
            'agents' => [['id' => 999]]
        ]);

        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $callCount = 0;
        $queueService->shouldReceive('getSnapshot')->andReturnUsing(function() use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return ['generation' => 'queue_gen_1', 'payload' => [['id' => 1]]];
            }
            return ['generation' => 'queue_gen_2', 'payload' => [['id' => 1]]];
        });
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => [
                ['UserID' => 1, 'UserLogin' => 'a', 'UserFullname' => 'a']
            ]]),
            '*/Agent/1/AssignableQueues*' => Http::response(['Queues' => [['QueueID' => 1]]]),
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertFailed();

        $meta = \Illuminate\Support\Facades\Cache::get('znuny_prewarm_agents_meta');
        $this->assertEquals('stale', $meta['status']);
        $this->assertEquals('old_agent_gen', $meta['active_generation']);
        $this->assertStringContainsString('generation changed or expired', $meta['last_error']);
    }

    public function test_integer_initial_queue_generation_is_rejected_before_znuny_request()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn([
            'generation' => 123,
            'payload' => [['id' => 1]]
        ]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        $this->artisan('znuny:cache:warm-agents')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_blank_initial_queue_generation_is_rejected()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn([
            'generation' => '   ',
            'payload' => [['id' => 1]]
        ]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        $this->artisan('znuny:cache:warm-agents')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_missing_final_queue_generation_is_rejected_cleanly()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $callCount = 0;
        $queueService->shouldReceive('getSnapshot')->andReturnUsing(function() use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return ['generation' => 'queue_gen_1', 'payload' => [['id' => 1]]];
            }
            return ['payload' => [['id' => 1]]]; // missing generation
        });
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => [
                ['UserID' => 1, 'UserLogin' => 'a', 'UserFullname' => 'a']
            ]]),
            '*/Agent/1/AssignableQueues*' => Http::response(['Queues' => [['QueueID' => 1]]]),
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertFailed();
    }

    public function test_successful_publication_records_exact_validated_queue_generation()
    {
        $queueService = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueService->shouldReceive('getSnapshot')->andReturn([
            'generation' => '  queue_gen_1  ',
            'payload' => [['id' => 1]]
        ]);
        $this->app->instance(ZnunyQueueCacheReadService::class, $queueService);

        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test']),
            '*Agent?SessionID=*' => Http::response(['Agents' => [
                ['UserID' => 1, 'UserLogin' => 'a', 'UserFullname' => 'a']
            ]]),
            '*/Agent/1/AssignableQueues*' => Http::response(['Queues' => [['QueueID' => 1]]]),
        ]);

        $this->artisan('znuny:cache:warm-agents')->assertSuccessful();

        $meta = \Illuminate\Support\Facades\Cache::get('znuny_prewarm_agents_meta');
        $payload = \Illuminate\Support\Facades\Cache::get($meta['active_generation']);

        $this->assertEquals('queue_gen_1', $payload['queue_generation']);
    }
}
