<?php

namespace Tests\Feature\Znuny\Cache;

use App\Services\Znuny\Cache\PrewarmSnapshotManager;
use App\Services\Znuny\ZnunyClient;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ZnunyWarmQueuesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
    }

    public function test_successful_warm_preserves_payload_and_sorts_deterministically()
    {
        $mockClient = Mockery::mock(ZnunyClient::class);
        // Provide payload already normalized by the client
        $mockClient->shouldReceive('getQueues')->once()->andReturn([
            ['id' => 2, 'name' => 'Zebra', 'full_name' => 'Zebra Full', 'valid_id' => 1, 'label' => 'Zebra Label'],
            ['id' => 1, 'name' => 'Apple', 'full_name' => 'Apple', 'valid_id' => 0, 'label' => 'Apple Label'],
            ['id' => 3, 'name' => 'Banana', 'full_name' => 'Banana', 'valid_id' => 1, 'label' => 'Banana Label'],
        ]);

        $this->app->instance(ZnunyClient::class, $mockClient);

        $this->artisan('znuny:cache:warm-queues')
            ->expectsOutput('Starting Znuny queues cache warmup...')
            ->expectsOutput('Successfully warmed Znuny queues cache.')
            ->assertExitCode(0);

        $manager = new PrewarmSnapshotManager('queues');
        $active = $manager->readActive();

        $this->assertCount(3, $active);
        
        // Deterministic ordering by name
        $this->assertEquals('Apple', $active[0]['name']);
        $this->assertEquals('Banana', $active[1]['name']);
        $this->assertEquals('Zebra', $active[2]['name']);
        
        // Exact payload preservation, no fabricated fields
        $this->assertEquals(0, $active[0]['valid_id']);
        $this->assertEquals('Apple Label', $active[0]['label']);
        $this->assertEquals('Apple', $active[0]['full_name']);
        $this->assertArrayNotHasKey('fabricated', $active[0]);
    }

    public function test_fails_on_malformed_normalized_entry()
    {
        $manager = new PrewarmSnapshotManager('queues');
        
        $mockClient = Mockery::mock(ZnunyClient::class);
        $mockClient->shouldReceive('getQueues')->once()->andReturn([
            ['id' => 1, 'name' => 'Apple', 'valid_id' => 1], // Valid
            ['id' => 2, 'valid_id' => 1], // Missing name
        ]);

        $this->app->instance(ZnunyClient::class, $mockClient);

        $this->artisan('znuny:cache:warm-queues')
            ->expectsOutput('Failed to warm queues cache. Error: Invalid payload: malformed normalized queue entry.')
            ->assertExitCode(1);
            
        $this->assertNull($manager->readActive());
    }

    public function test_fails_on_empty_payload_and_preserves_old()
    {
        $manager = new PrewarmSnapshotManager('queues');
        $manager->refresh(function () {
            return [['id' => 1, 'name' => 'Old']];
        });

        $mockClient = Mockery::mock(ZnunyClient::class);
        $mockClient->shouldReceive('getQueues')->once()->andReturn([]);
        $this->app->instance(ZnunyClient::class, $mockClient);

        $this->artisan('znuny:cache:warm-queues')
            ->expectsOutput('Failed to warm queues cache. Error: Invalid payload: unexpectedly empty or invalid.')
            ->assertExitCode(1);

        $active = $manager->readActive();
        $this->assertCount(1, $active);
        $this->assertEquals('Old', $active[0]['name']);
    }

    public function test_fails_on_api_exception()
    {
        $mockClient = Mockery::mock(ZnunyClient::class);
        $mockClient->shouldReceive('getQueues')->once()->andThrow(new \Exception('Network error'));
        $this->app->instance(ZnunyClient::class, $mockClient);

        $this->artisan('znuny:cache:warm-queues')
            ->expectsOutput('Failed to warm queues cache. Error: Network error')
            ->assertExitCode(1);
    }
}
