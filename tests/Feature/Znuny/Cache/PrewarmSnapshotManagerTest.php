<?php

namespace Tests\Feature\Znuny\Cache;

use App\Services\Znuny\Cache\PrewarmSnapshotManager;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PrewarmSnapshotManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
    }

    public function test_successful_first_warm_creates_and_activates_snapshot()
    {
        $manager = new PrewarmSnapshotManager('test_dataset');
        
        $this->assertNull($manager->readActive());
        
        $success = $manager->refresh(function () {
            return [['id' => 1, 'name' => 'Data 1']];
        });

        $this->assertTrue($success);
        
        $active = $manager->readActive();
        $this->assertIsArray($active);
        $this->assertCount(1, $active);
        $this->assertEquals('Data 1', $active[0]['name']);
        
        $meta = $manager->readMetadata();
        $this->assertEquals('ready', $meta['status']);
        $this->assertNotNull($meta['active_generation']);
        $this->assertEquals(1, $meta['item_count']);
    }

    public function test_successful_second_warm_replaces_first_snapshot_and_removes_old()
    {
        $manager = new PrewarmSnapshotManager('test_dataset');
        
        $manager->refresh(function () {
            return [['id' => 1, 'name' => 'Data 1']];
        });

        $firstMeta = $manager->readMetadata();
        $firstGen = $firstMeta['active_generation'];

        $this->assertTrue(Cache::has($firstGen));

        $manager->refresh(function () {
            return [['id' => 2, 'name' => 'Data 2']];
        });

        $secondMeta = $manager->readMetadata();
        $secondGen = $secondMeta['active_generation'];

        $this->assertNotEquals($firstGen, $secondGen);
        $this->assertFalse(Cache::has($firstGen)); // Old is removed
        $this->assertTrue(Cache::has($secondGen));

        $active = $manager->readActive();
        $this->assertEquals('Data 2', $active[0]['name']);
    }

    public function test_failure_preserves_previous_active_snapshot()
    {
        $manager = new PrewarmSnapshotManager('test_dataset');
        
        $manager->refresh(function () {
            return [['id' => 1, 'name' => 'Data 1']];
        });

        $firstGen = $manager->readMetadata()['active_generation'];

        $success = $manager->refresh(function () {
            throw new \Exception('API Down password=secret123');
        });

        $this->assertFalse($success);

        $meta = $manager->readMetadata();
        $this->assertEquals('stale', $meta['status']);
        $this->assertEquals($firstGen, $meta['active_generation']);
        $this->assertStringContainsString('API Down', $meta['last_error']);
        $this->assertStringNotContainsString('secret123', $meta['last_error']);
        $this->assertStringContainsString('password=***', $meta['last_error']);

        $active = $manager->readActive();
        $this->assertEquals('Data 1', $active[0]['name']);
    }

    public function test_invalid_payload_preserves_previous_snapshot()
    {
        $manager = new PrewarmSnapshotManager('test_dataset');
        
        $manager->refresh(function () {
            return [['id' => 1]];
        });

        $success = $manager->refresh(function () {
            return "not an array";
        });

        $this->assertFalse($success);

        $active = $manager->readActive();
        $this->assertIsArray($active);
        $this->assertEquals(1, $active[0]['id']);
    }

    public function test_concurrent_invocation_does_not_run_second_refresh()
    {
        $manager = new PrewarmSnapshotManager('test_dataset');
        
        // Manually lock it
        $lock = Cache::lock('znuny_prewarm_test_dataset_lock', 120);
        $lock->get();

        $success = $manager->refresh(function () {
            return [['id' => 1]];
        });

        $this->assertFalse($success);
        
        $lock->release();
    }

    public function test_post_write_failure_removes_temporary_snapshot_and_preserves_active()
    {
        $manager = new class('test_dataset') extends PrewarmSnapshotManager {
            public int $saveCount = 0;
            public ?string $capturedTempKey = null;

            protected function saveMetadata(array $meta): void
            {
                $this->saveCount++;
                // 1st call: first refresh start
                // 2nd call: first refresh success
                // 3rd call: second refresh start
                // 4th call: second refresh success -> THROW HERE
                if ($this->saveCount === 4) {
                    $this->capturedTempKey = $meta['active_generation'];
                    throw new \Exception('Simulated post-write failure password=secret123');
                }
                parent::saveMetadata($meta);
            }
        };

        // 1. A valid active snapshot already exists.
        $manager->refresh(function () {
            return [['id' => 1, 'name' => 'Data 1']];
        });

        $firstGen = $manager->readMetadata()['active_generation'];
        $this->assertTrue(Cache::has($firstGen));

        // 2 & 3. Second refresh writes payload but fails before metadata activates.
        $success = $manager->refresh(function () {
            return [['id' => 2, 'name' => 'Data 2']];
        });

        $this->assertFalse($success);

        // 4. Previous active remains readable
        $active = $manager->readActive();
        $this->assertEquals('Data 1', $active[0]['name']);
        $this->assertTrue(Cache::has($firstGen));

        // 5. Temporary generation is removed
        $this->assertNotNull($manager->capturedTempKey);
        $this->assertFalse(Cache::has($manager->capturedTempKey));

        // 6 & 7. Metadata is stale and error sanitized
        $meta = $manager->readMetadata();
        $this->assertEquals('stale', $meta['status']);
        $this->assertEquals($firstGen, $meta['active_generation']);
        $this->assertStringContainsString('Simulated post-write failure password=***', $meta['last_error']);
    }

    public function test_first_refresh_failure_leaves_no_generation()
    {
        $manager = new PrewarmSnapshotManager('test_dataset');
        
        $success = $manager->refresh(function () {
            throw new \Exception('API completely down');
        });

        $this->assertFalse($success);

        $this->assertNull($manager->readActive());

        $meta = $manager->readMetadata();
        $this->assertEquals('failed', $meta['status']);
        $this->assertNull($meta['active_generation']);
    }

    public function test_comprehensive_error_sanitization()
    {
        $manager = new PrewarmSnapshotManager('test_dataset');
        
        $secrets = [
            'password=secret123',
            'password: secret123',
            '"password":"secret123"',
            "'password':'secret123'",
            'token=secret123',
            'token: secret123',
            '"token":"secret123"',
            'Authorization: Bearer secret123',
            'authorization=Bearer secret123',
            '?password=secret123',
            '&token=secret123',
            '?access_token=secret123',
            "api_key: secret123\nother context",
        ];

        foreach ($secrets as $secretStr) {
            $manager->refresh(function () use ($secretStr) {
                throw new \Exception("Error occurred with {$secretStr} inside");
            });

            $meta = $manager->readMetadata();
            $error = $meta['last_error'];

            $this->assertStringNotContainsString('secret123', $error, "Secret leaked in: {$secretStr}");
            $this->assertStringContainsString('***', $error, "Secret not replaced in: {$secretStr}");
            $this->assertStringContainsString('Error occurred with', $error, "Context lost in: {$secretStr}");
        }
    }
}
