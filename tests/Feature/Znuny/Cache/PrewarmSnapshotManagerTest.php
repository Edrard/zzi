<?php

namespace Tests\Feature\Znuny\Cache;

use App\Enums\ZnunyPrewarmRefreshResult;
use App\Services\Znuny\Cache\PrewarmSnapshotManager;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TestablePrewarmSnapshotManager extends PrewarmSnapshotManager
{
    public $mockStoreCacheResult = true;
    public $mockStoreCacheCallback = null;
    public $mockForgetCacheResult = true;
    public $mockForgetCacheCallback = null;
    public $mockAcquireLockException = false;
    public $mockAcquireLockResult = true;
    public $mockReleaseLockResult = true;
    public $mockReleaseLockException = false;
    public $mockGetCacheCallback = null;

    public $storeCalls = [];
    public $forgetCalls = [];
    public $getCacheCalls = [];
    public $acquireLockCalls = [];
    public $releaseLockCalls = [];

    protected function getCache(string $key)
    {
        $this->getCacheCalls[] = $key;
        if ($this->mockGetCacheCallback) {
            return ($this->mockGetCacheCallback)($key);
        }
        return parent::getCache($key);
    }

    protected function storeCache(string $key, $value, int $ttlMinutes): bool
    {
        $this->storeCalls[] = ['key' => $key, 'value' => $value, 'ttl' => $ttlMinutes];
        if ($this->mockStoreCacheCallback) {
            return ($this->mockStoreCacheCallback)($key, $value, $ttlMinutes);
        }
        if ($this->mockStoreCacheResult !== true) {
            return false;
        }
        return parent::storeCache($key, $value, $ttlMinutes);
    }

    protected function forgetCache(string $key): bool
    {
        $this->forgetCalls[] = $key;
        if ($this->mockForgetCacheCallback) {
            return ($this->mockForgetCacheCallback)($key);
        }
        if ($this->mockForgetCacheResult !== true) {
            return false;
        }
        return parent::forgetCache($key);
    }

    protected function acquireLock(string $key, int $seconds)
    {
        $this->acquireLockCalls[] = ['key' => $key, 'seconds' => $seconds];
        if ($this->mockAcquireLockException) {
            throw new \Exception('Simulated lock acquire exception.');
        }
        if (! $this->mockAcquireLockResult) {
            return false;
        }
        return parent::acquireLock($key, $seconds);
    }

    protected function releaseLock($lock): bool
    {
        $this->releaseLockCalls[] = $lock;
        if ($this->mockReleaseLockException) {
            throw new \Exception('Simulated lock release exception.');
        }
        if ($this->mockReleaseLockResult !== true) {
            return false;
        }
        return parent::releaseLock($lock);
    }

    public function exposeSanitizeError(string $e)
    {
        return $this->sanitizeError($e);
    }
}

class PrewarmSnapshotManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::clear();
        parent::tearDown();
    }

    public function test_temporary_payload_write_returns_false()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        $manager->mockStoreCacheCallback = function ($key, $value, $ttl) {
            if (str_contains($key, '_v')) {
                return false; // temp payload fail
            }
            return Cache::put($key, $value, now()->addMinutes($ttl));
        };

        $result = $manager->refresh(fn() => ['payload' => [1], 'item_count' => 1], 'artisan', 10);
        $this->assertEquals(ZnunyPrewarmRefreshResult::FAILED, $result);

        $meta = $manager->readMetadata();
        $this->assertEquals('failed', $meta['status']);
        $this->assertNull($meta['active_generation']);
    }

    public function test_corrupted_scalar_metadata_returns_default()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        $manager->mockGetCacheCallback = function ($key) {
            return true; // truthy scalar instead of array
        };

        $meta = $manager->readMetadata();
        $this->assertIsArray($meta);
        $this->assertEquals('test_dataset', $meta['dataset_name']);
        $this->assertEquals('missing', $meta['status']);
        $this->assertNull($meta['active_generation']);
    }

    public function test_initial_metadata_read_throws()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        $manager->mockGetCacheCallback = function ($key) {
            if (str_ends_with($key, '_meta')) {
                throw new \Exception('Initial read fail');
            }
            return Cache::get($key);
        };

        $result = $manager->refresh(fn() => ['payload' => [1], 'item_count' => 1], 'artisan', 10);
        $this->assertEquals(ZnunyPrewarmRefreshResult::FAILED, $result);
    }

    public function test_initial_metadata_write_returns_false()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        Cache::put('znuny_prewarm_test_dataset_meta', [
            'active_generation' => 'old_gen_1',
            'status' => 'ready'
        ]);

        $manager->mockStoreCacheCallback = function ($key, $value, $ttl) {
            if (str_ends_with($key, '_meta') && ($value['status'] ?? '') === 'refreshing') {
                return false;
            }
            return Cache::put($key, $value, now()->addMinutes($ttl));
        };

        $result = $manager->refresh(fn() => ['payload' => [1], 'item_count' => 1], 'artisan', 10);
        $this->assertEquals(ZnunyPrewarmRefreshResult::FAILED, $result);

        $meta = $manager->readMetadata();
        $this->assertEquals('stale', $meta['status']);
        $this->assertEquals('old_gen_1', $meta['active_generation']);
        $this->assertStringContainsString('Cache::put returned false for metadata write', $meta['last_error']);
    }

    public function test_activation_metadata_write_returns_false()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        $manager->mockStoreCacheCallback = function ($key, $value, $ttl) {
            if (str_ends_with($key, '_meta') && ($value['status'] ?? '') === 'ready') {
                return false;
            }
            return Cache::put($key, $value, now()->addMinutes($ttl));
        };

        $result = $manager->refresh(fn() => ['payload' => [1], 'item_count' => 1], 'artisan', 10);
        $this->assertEquals(ZnunyPrewarmRefreshResult::FAILED, $result);

        // Verify temp generation was cleaned up
        $tempKey = null;
        foreach ($manager->forgetCalls as $forgetKey) {
            if (str_contains($forgetKey, '_v')) {
                $tempKey = $forgetKey;
            }
        }
        $this->assertNotNull($tempKey, 'Temp key should be deleted on activation write failure.');
    }

    public function test_activation_metadata_write_throws()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        $manager->mockStoreCacheCallback = function ($key, $value, $ttl) {
            if (str_ends_with($key, '_meta') && ($value['status'] ?? '') === 'ready') {
                throw new \Exception('Simulated activation error');
            }
            return Cache::put($key, $value, now()->addMinutes($ttl));
        };

        $result = $manager->refresh(fn() => ['payload' => [1], 'item_count' => 1], 'artisan', 10);
        $this->assertEquals(ZnunyPrewarmRefreshResult::FAILED, $result);

        $tempKey = null;
        foreach ($manager->forgetCalls as $forgetKey) {
            if (str_contains($forgetKey, '_v')) {
                $tempKey = $forgetKey;
            }
        }
        $this->assertNotNull($tempKey, 'Temp key should be deleted on activation write failure.');
    }

    public function test_temporary_delete_returns_false()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');

        // Force activation write to fail so it attempts to delete the temp payload
        $manager->mockStoreCacheCallback = function ($key, $value, $ttl) {
            if (str_ends_with($key, '_meta') && ($value['status'] ?? '') === 'ready') {
                return false;
            }
            return Cache::put($key, $value, now()->addMinutes($ttl));
        };

        // Force temporary delete to fail
        $manager->mockForgetCacheResult = false;

        $result = $manager->refresh(fn() => ['payload' => [1], 'item_count' => 1], 'artisan', 10);
        $this->assertEquals(ZnunyPrewarmRefreshResult::FAILED, $result);

        // Assert temp payload write happened with finite TTL
        $tempWrite = null;
        foreach ($manager->storeCalls as $call) {
            if (str_contains($call['key'], '_v')) {
                $tempWrite = $call;
            }
        }
        $this->assertNotNull($tempWrite);
        $this->assertGreaterThan(0, $tempWrite['ttl']);
    }

    public function test_old_generation_delete_returns_false_but_result_remains_success()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        // Setup existing old generation
        Cache::put('znuny_prewarm_test_dataset_meta', [
            'active_generation' => 'old_gen_1',
            'status' => 'ready'
        ]);

        $manager->mockForgetCacheResult = false;

        $result = $manager->refresh(fn() => ['payload' => [1], 'item_count' => 1], 'artisan', 10);
        $this->assertEquals(ZnunyPrewarmRefreshResult::SUCCESS, $result);
    }

    public function test_old_generation_delete_throws_but_result_remains_success()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        Cache::put('znuny_prewarm_test_dataset_meta', [
            'active_generation' => 'old_gen_1',
            'status' => 'ready'
        ]);

        $manager->mockForgetCacheCallback = function ($key) {
            throw new \Exception('Delete threw');
        };

        $result = $manager->refresh(fn() => ['payload' => [1], 'item_count' => 1], 'artisan', 10);
        $this->assertEquals(ZnunyPrewarmRefreshResult::SUCCESS, $result);
    }

    public function test_lock_acquire_throws()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        $manager->mockAcquireLockException = true;

        $result = $manager->refresh(fn() => ['payload' => [1], 'item_count' => 1], 'artisan', 10);
        $this->assertEquals(ZnunyPrewarmRefreshResult::FAILED, $result);
    }

    public function test_lock_contention_returns_skipped()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        $manager->mockAcquireLockResult = false;

        $result = $manager->refresh(fn() => ['payload' => [1], 'item_count' => 1], 'artisan', 10);
        $this->assertEquals(ZnunyPrewarmRefreshResult::SKIPPED_LOCKED, $result);
    }

    public function test_lock_release_returns_false_without_changing_success()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        $manager->mockReleaseLockResult = false;

        $result = $manager->refresh(fn() => ['payload' => [1], 'item_count' => 1], 'artisan', 10);
        $this->assertEquals(ZnunyPrewarmRefreshResult::SUCCESS, $result);
    }

    public function test_lock_release_throws_without_changing_success()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        $manager->mockReleaseLockException = true;

        $result = $manager->refresh(fn() => ['payload' => [1], 'item_count' => 1], 'artisan', 10);
        $this->assertEquals(ZnunyPrewarmRefreshResult::SUCCESS, $result);
    }

    public function test_scalar_payload_rejected()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        $result = $manager->refresh(fn() => ['payload' => 123, 'item_count' => 1], 'artisan', 10);
        $this->assertEquals(ZnunyPrewarmRefreshResult::FAILED, $result);
        $this->assertStringContainsString('payload array', $manager->readMetadata()['last_error']);
    }

    public function test_invalid_item_count_rejected()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        $result = $manager->refresh(fn() => ['payload' => [1], 'item_count' => -1], 'artisan', 10);
        $this->assertEquals(ZnunyPrewarmRefreshResult::FAILED, $result);

        $result2 = $manager->refresh(fn() => ['payload' => [1], 'item_count' => '1'], 'artisan', 10);
        $this->assertEquals(ZnunyPrewarmRefreshResult::FAILED, $result2);
    }

    public function test_real_pointer_race_retry_returns_new_generation()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        $callCount = 0;

        Cache::put('znuny_prewarm_test_dataset_meta', [
            'active_generation' => 'gen_A',
            'status' => 'ready'
        ]);

        // gen_A payload DOES exist
        Cache::put('gen_A', ['old_payload_data'], now()->addMinutes(10));
        Cache::put('gen_B', ['payload_data'], now()->addMinutes(10));

        $manager->mockGetCacheCallback = function ($key) use (&$callCount) {
            if ($key === 'znuny_prewarm_test_dataset_meta') {
                $callCount++;
                if ($callCount === 1) {
                    // First read before payload
                    return ['active_generation' => 'gen_A', 'status' => 'ready'];
                }
                // Second read after payload read, pointer changed to B
                return ['active_generation' => 'gen_B', 'status' => 'ready'];
            }
            return Cache::get($key);
        };

        $result = $manager->readActiveSnapshot();

        $this->assertEquals('gen_B', $result['generation']);
        $this->assertEquals(['payload_data'], $result['payload']);
        $this->assertEquals('gen_B', $result['metadata']['active_generation']);
    }

    public function test_active_payload_readable_while_metadata_is_refreshing()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        Cache::put('znuny_prewarm_test_dataset_meta', [
            'active_generation' => 'gen_X',
            'status' => 'refreshing'
        ]);
        Cache::put('gen_X', ['some' => 'data'], now()->addMinutes(10));

        $this->assertEquals(['some' => 'data'], $manager->readActive());
    }

    public function test_active_payload_readable_while_metadata_is_stale()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        Cache::put('znuny_prewarm_test_dataset_meta', [
            'active_generation' => 'gen_Y',
            'status' => 'stale'
        ]);
        Cache::put('gen_Y', ['stale' => 'data'], now()->addMinutes(10));

        $this->assertEquals(['stale' => 'data'], $manager->readActive());
    }

    public function test_comprehensive_sanitizer_cases()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        $cases = [
            'Basic error' => 'Basic error',
            'password="dummy_secret"' => 'password="***"',
            'api_key: dummy_secret' => 'api_key: ***',
            'SessionID=abc12345' => 'SessionID=***',
            'api_key="sec ret"' => 'api_key="***"',
            'session_id: "abc"' => 'session_id: "***"',
            'Bearer TEST_DUMMY_BEARER_TOKEN_123456' => 'Bearer ***',
            'Authorization: Bearer TEST_DUMMY_BEARER_TOKEN_123456' => 'Authorization: Bearer ***',
            'Some token=123 value' => 'Some token=*** value',
            "Message\nStack trace:\n#0 file.php:123" => "Message",
            "Message\nSTACK TRACE:\n#0 file.php" => "Message",
        ];

        foreach ($cases as $input => $expected) {
            $this->assertEquals($expected, $manager->exposeSanitizeError($input));
        }

        $long = str_repeat('a', 600);
        $this->assertEquals(500, strlen($manager->exposeSanitizeError($long)));
    }
    public function test_expired_payload_with_remaining_metadata_returns_no_snapshot()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        Cache::put('znuny_prewarm_test_dataset_meta', [
            'active_generation' => 'gen_Z',
            'status' => 'ready'
        ]);
        // gen_Z payload is missing (expired)

        $snapshot = $manager->readActiveSnapshot();
        $this->assertNull($snapshot);

        // Active payload returns null as well
        $payload = $manager->readActive();
        $this->assertNull($payload);
    }

    public function test_missing_metadata_returns_default_missing_state()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        $meta = $manager->readMetadata();
        $this->assertEquals('test_dataset', $meta['dataset_name']);
        $this->assertEquals('missing', $meta['status']);
        $this->assertNull($meta['active_generation']);
    }

    public function test_reads_perform_no_cache_writes()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        Cache::put('znuny_prewarm_test_dataset_meta', [
            'active_generation' => 'gen_A',
            'status' => 'ready'
        ]);
        Cache::put('gen_A', ['data']);

        $snapshot = $manager->readActiveSnapshot();
        $this->assertNotNull($snapshot);

        $this->assertEmpty($manager->storeCalls);
        $this->assertEmpty($manager->forgetCalls);
    }

    public function test_partial_or_corrupted_metadata_normalization_including_invalid_status()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        Cache::put('znuny_prewarm_test_dataset_meta', [
            'dataset_name' => 'malicious_name',
            'active_generation' => '   ', // whitespace only
            'status' => 'invalid_status',
            'item_count' => -5,
            'last_attempt_at' => ['malformed'],
            'last_successful_refresh_at' => 12345,
            'last_error' => ['error'],
            'refresh_source' => 1,
        ]);

        $meta = $manager->readMetadata();
        $this->assertEquals('test_dataset', $meta['dataset_name']); // forced to manager dataset
        $this->assertNull($meta['active_generation']); // whitespace becomes null
        $this->assertEquals('missing', $meta['status']); // invalid status without active gen becomes missing
        $this->assertEquals(0, $meta['item_count']); // negative becomes 0
        $this->assertNull($meta['last_attempt_at']); // array becomes null
        $this->assertNull($meta['last_successful_refresh_at']); // int becomes null
        $this->assertNull($meta['last_error']); // array becomes null
        $this->assertNull($meta['refresh_source']); // int becomes null

        // Test with active generation and surrounding whitespace
        Cache::put('znuny_prewarm_test_dataset_meta', [
            'active_generation' => '  gen_1  ',
            'status' => 'invalid_status',
        ]);
        $meta = $manager->readMetadata();
        $this->assertEquals('gen_1', $meta['active_generation']); // trimmed
        $this->assertEquals('ready', $meta['status']); // invalid status with active gen becomes ready
    }

    public function test_successful_publication_uses_expected_ttls_and_default_lock()
    {
        config(['app.znuny_prewarm.cache_ttl_multiplier' => 10]);
        config(['app.znuny_prewarm.metadata_ttl_minutes' => 10080]);
        config(['app.znuny_prewarm.process_timeout_seconds' => 600]);
        config(['app.znuny_prewarm.lock_expiry_grace_seconds' => 60]);
        config(['app.znuny_prewarm.lock_expiry_seconds' => 9999]);

        $manager = new TestablePrewarmSnapshotManager('test_dataset');

        // Test with refreshIntervalMinutes = 5
        $result = $manager->refresh(fn() => ['payload' => [1], 'item_count' => 1], 'artisan', 5);
        $this->assertEquals(ZnunyPrewarmRefreshResult::SUCCESS, $result);

        // Store calls should be: [metadata refreshing (10080)], [payload (50)], [metadata ready (10080)]
        $this->assertCount(3, $manager->storeCalls);

        $this->assertEquals(10080, $manager->storeCalls[0]['ttl']); // meta refreshing
        $this->assertEquals(50, $manager->storeCalls[1]['ttl']); // payload
        $this->assertEquals(10080, $manager->storeCalls[2]['ttl']); // meta activation

        $this->assertCount(1, $manager->acquireLockCalls);
        $this->assertEquals(660, $manager->acquireLockCalls[0]['seconds']);

        $meta = $manager->readMetadata();
        $this->assertEquals('ready', $meta['status']);
        $this->assertNotEmpty($meta['active_generation']);
        $this->assertEquals(1, $meta['item_count']);

        $payload = $manager->readActive();
        $this->assertEquals([1], $payload);
    }

    public function test_second_successful_publication_replaces_active_generation_and_cleans_up_old()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        $manager->refresh(fn() => ['payload' => [1], 'item_count' => 1], 'artisan', 5);

        $meta1 = $manager->readMetadata();
        $gen1 = $meta1['active_generation'];

        $manager->storeCalls = [];
        $manager->forgetCalls = [];

        $result = $manager->refresh(fn() => ['payload' => [2], 'item_count' => 2], 'artisan', 5);
        $this->assertEquals(ZnunyPrewarmRefreshResult::SUCCESS, $result);

        $meta2 = $manager->readMetadata();
        $gen2 = $meta2['active_generation'];

        $this->assertNotEmpty($gen2);
        $this->assertNotEquals($gen1, $gen2);
        $this->assertEquals(2, $meta2['item_count']);

        $this->assertEquals([2], $manager->readActive());

        $this->assertContains($gen1, $manager->forgetCalls);
        $this->assertEquals(3, count($manager->storeCalls)); // Metadata remains one key
    }

    public function test_fetch_exception_with_old_snapshot()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        $manager->refresh(fn() => ['payload' => [1], 'item_count' => 1], 'artisan', 5);

        $meta1 = $manager->readMetadata();
        $gen1 = $meta1['active_generation'];

        $manager->storeCalls = [];

        $result = $manager->refresh(function () {
            throw new \Exception('Fetch failed');
        }, 'test_source', 5);

        $this->assertEquals(ZnunyPrewarmRefreshResult::FAILED, $result);

        $meta2 = $manager->readMetadata();
        $this->assertEquals('stale', $meta2['status']);
        $this->assertEquals($gen1, $meta2['active_generation']);
        $this->assertEquals('test_source', $meta2['refresh_source']);
        $this->assertEquals([1], $manager->readActive());

        // Assert old payload was not rewritten (only 2 metadata stores: refreshing, then stale)
        $this->assertCount(2, $manager->storeCalls);
    }

    public function test_first_fetch_exception_leaves_failed_state()
    {
        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        $result = $manager->refresh(function () {
            throw new \Exception('Fetch failed');
        }, 'test_source', 5);

        $this->assertEquals(ZnunyPrewarmRefreshResult::FAILED, $result);

        $meta = $manager->readMetadata();
        $this->assertEquals('failed', $meta['status']);
        $this->assertNull($meta['active_generation']);
        $this->assertEquals('test_source', $meta['refresh_source']);
    }

    public function test_ttls_are_clamped_and_metadata_ttl_not_shorter_than_payload_ttl()
    {
        config(['app.znuny_prewarm.cache_ttl_multiplier' => -5]); // Should clamp to 1
        config(['app.znuny_prewarm.metadata_ttl_minutes' => -10]); // Should clamp to max(1, payload_ttl)

        $manager = new TestablePrewarmSnapshotManager('test_dataset');
        $manager->refresh(fn() => ['payload' => [1], 'item_count' => 1], 'artisan', -5); // interval clamped to 1

        $this->assertCount(3, $manager->storeCalls);
        $this->assertEquals(1, $manager->storeCalls[0]['ttl']); // meta refreshing
        $this->assertEquals(1, $manager->storeCalls[1]['ttl']); // payload
        $this->assertEquals(1, $manager->storeCalls[2]['ttl']); // meta activation
    }
}
