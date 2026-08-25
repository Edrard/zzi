<?php

namespace App\Services\Znuny\Cache;

use App\Enums\ZnunyPrewarmRefreshResult;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PrewarmSnapshotManager
{
    private string $datasetName;

    public function __construct(string $datasetName)
    {
        $this->datasetName = $datasetName;
    }

    /**
     * Get the active snapshot (generation, payload, metadata) coherently.
     * Returns null if missing or expired.
     */
    public function readActiveSnapshot(): ?array
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $metaBefore = $this->readMetadata();
            $genBefore = $metaBefore['active_generation'];

            if (! $genBefore) {
                continue;
            }

            $payload = $this->getCache($genBefore);

            $metaAfter = $this->readMetadata();
            $genAfter = $metaAfter['active_generation'];

            if ($genBefore === $genAfter && is_array($payload)) {
                return [
                    'generation' => $genBefore,
                    'payload' => $payload,
                    'metadata' => $metaAfter,
                ];
            }
        }

        return null;
    }

    /**
     * Get the active payload, or null if none is ready.
     */
    public function readActive(): ?array
    {
        $snapshot = $this->readActiveSnapshot();
        return $snapshot ? $snapshot['payload'] : null;
    }

    /**
     * Get the current metadata for the dataset.
     */
    public function readMetadata(): array
    {
        $meta = $this->getCache($this->getMetaKey());

        $base = [
            'dataset_name' => $this->datasetName,
            'status' => 'missing',
            'active_generation' => null,
            'item_count' => 0,
            'last_attempt_at' => null,
            'last_successful_refresh_at' => null,
            'last_error' => null,
            'refresh_source' => null,
        ];

        if (is_array($meta)) {
            $gen = (isset($meta['active_generation']) && is_string($meta['active_generation'])) ? trim($meta['active_generation']) : '';
            $base['active_generation'] = $gen !== '' ? $gen : null;
            $base['item_count'] = (isset($meta['item_count']) && is_int($meta['item_count']) && $meta['item_count'] >= 0) ? $meta['item_count'] : 0;
            $allowedStatuses = ['missing', 'refreshing', 'ready', 'stale', 'failed'];
            $base['status'] = (isset($meta['status']) && is_string($meta['status']) && in_array($meta['status'], $allowedStatuses, true)) ? $meta['status'] : ($base['active_generation'] ? 'ready' : 'missing');
            $base['last_attempt_at'] = (isset($meta['last_attempt_at']) && is_string($meta['last_attempt_at'])) ? $meta['last_attempt_at'] : null;
            $base['last_successful_refresh_at'] = (isset($meta['last_successful_refresh_at']) && is_string($meta['last_successful_refresh_at'])) ? $meta['last_successful_refresh_at'] : null;
            $base['last_error'] = (isset($meta['last_error']) && is_string($meta['last_error'])) ? $meta['last_error'] : null;
            $base['refresh_source'] = (isset($meta['refresh_source']) && is_string($meta['refresh_source'])) ? $meta['refresh_source'] : null;
            $base['dataset_name'] = $this->datasetName;
        }

        return $base;
    }

    /**
     * Refresh the snapshot atomically.
     * Note: Parent-runner lock ownership and environment-derived hard timeout
     * are deferred to the scheduler/runner stage.
     */
    public function refresh(Closure $fetcher, string $source, int $refreshIntervalMinutes, ?int $lockSeconds = null): ZnunyPrewarmRefreshResult
    {
        $timeout = max(1, (int) config('app.znuny_prewarm.process_timeout_seconds', 600));
        $grace = max(0, (int) config('app.znuny_prewarm.lock_expiry_grace_seconds', 60));
        $effectiveLockExpiry = $timeout + $grace;
        $lockSeconds = $lockSeconds ?? $effectiveLockExpiry;

        try {
            $lock = $this->acquireLock($this->getLockKey(), $lockSeconds);
            if (! $lock) {
                return ZnunyPrewarmRefreshResult::SKIPPED_LOCKED;
            }
        } catch (\Throwable $e) {
            Log::error("Failed to acquire lock for prewarm dataset [{$this->datasetName}]: " . $this->sanitizeError($e->getMessage()));
            return ZnunyPrewarmRefreshResult::FAILED;
        }

        try {
            $effectiveRefreshIntervalMinutes = max(1, $refreshIntervalMinutes);
            $cacheTtlMultiplier = max(
                1,
                (int) config('app.znuny_prewarm.cache_ttl_multiplier', 10),
            );
            $configuredMetadataTtlMinutes = max(
                1,
                (int) config('app.znuny_prewarm.metadata_ttl_minutes', 10080),
            );

            $payloadTtlMinutes =
                $effectiveRefreshIntervalMinutes * $cacheTtlMultiplier;

            $metadataTtlMinutes = max(
                $payloadTtlMinutes,
                $configuredMetadataTtlMinutes,
            );

            try {
                $originalMeta = $this->readMetadata();
            } catch (\Throwable $e) {
                Log::error("Failed to read initial metadata for prewarm dataset [{$this->datasetName}]: " . $this->sanitizeError($e->getMessage()));
                return ZnunyPrewarmRefreshResult::FAILED;
            }

            $meta = $originalMeta;
            $meta['last_attempt_at'] = now()->toIso8601String();
            $meta['status'] = 'refreshing';
            $meta['refresh_source'] = $source;

            $tempKey = $this->getDatasetKeyPrefix() . '_v' . time() . '_' . uniqid();
            $tempWritten = false;
            $activated = false;
            $successResult = ZnunyPrewarmRefreshResult::SUCCESS;

            try {
                if (! $this->storeCache($this->getMetaKey(), $meta, $metadataTtlMinutes)) {
                    throw new \Exception("Cache::put returned false for metadata write.");
                }

                $fetchResult = $fetcher();

                if (! is_array($fetchResult) || ! isset($fetchResult['payload']) || ! is_array($fetchResult['payload']) || ! isset($fetchResult['item_count']) || ! is_int($fetchResult['item_count']) || $fetchResult['item_count'] < 0) {
                    throw new \Exception('Fetcher must return array with valid payload array and positive integer item_count.');
                }

                if (! $this->storeCache($tempKey, $fetchResult['payload'], $payloadTtlMinutes)) {
                    throw new \Exception("Cache::put returned false for temporary payload write.");
                }

                $tempWritten = true;

                $oldGeneration = $originalMeta['active_generation'];

                $meta['active_generation'] = $tempKey;
                $meta['status'] = 'ready';
                $meta['item_count'] = $fetchResult['item_count'];
                $meta['last_successful_refresh_at'] = now()->toIso8601String();
                $meta['last_error'] = null;

                if (! $this->storeCache($this->getMetaKey(), $meta, $metadataTtlMinutes)) {
                    throw new \Exception("Cache::put returned false for activation metadata write.");
                }

                $activated = true;

                // Cleanup old snapshot best-effort
                if ($oldGeneration && $oldGeneration !== $tempKey) {
                    try {
                        if (! $this->forgetCache($oldGeneration)) {
                            Log::warning("Failed to cleanup old generation for prewarm dataset [{$this->datasetName}]: returned false.");
                        }
                    } catch (\Throwable $ce) {
                        Log::warning("Failed to cleanup old generation for prewarm dataset [{$this->datasetName}]: " . $this->sanitizeError($ce->getMessage()));
                    }
                }

                return $successResult;
            } catch (\Throwable $e) {
                $sanitizedError = $this->sanitizeError($e->getMessage());
                Log::error("Failed to refresh prewarm dataset [{$this->datasetName}]: " . $sanitizedError);

                if (! $activated && $tempWritten) {
                    try {
                        if (! $this->forgetCache($tempKey)) {
                            Log::warning("Failed to cleanup temp generation for prewarm dataset [{$this->datasetName}]: returned false.");
                        }
                    } catch (\Throwable $ce) {
                        Log::warning("Failed to cleanup temp generation for prewarm dataset [{$this->datasetName}]: " . $this->sanitizeError($ce->getMessage()));
                    }
                }

                if (! $activated) {
                    try {
                        $failMeta = $originalMeta;
                        $failMeta['status'] = !empty($failMeta['active_generation']) ? 'stale' : 'failed';
                        $failMeta['last_error'] = $sanitizedError;
                        $failMeta['last_attempt_at'] = now()->toIso8601String();
                        $failMeta['refresh_source'] = $source;

                        if (! $this->storeCache($this->getMetaKey(), $failMeta, $metadataTtlMinutes)) {
                            Log::warning("Failed to save error metadata for prewarm dataset [{$this->datasetName}]: returned false.");
                        }
                    } catch (\Throwable $me) {
                        Log::warning("Failed to save error metadata for prewarm dataset [{$this->datasetName}]: " . $this->sanitizeError($me->getMessage()));
                    }
                }

                return ZnunyPrewarmRefreshResult::FAILED;
            }
        } finally {
            try {
                if (! $this->releaseLock($lock)) {
                    Log::warning("Failed to release lock for prewarm dataset [{$this->datasetName}]: returned false.");
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to release lock for prewarm dataset [{$this->datasetName}]: " . $this->sanitizeError($e->getMessage()));
            }
        }
    }

    private function getMetaKey(): string
    {
        return $this->getDatasetKeyPrefix() . '_meta';
    }

    private function getLockKey(): string
    {
        return $this->getDatasetKeyPrefix() . '_lock';
    }

    private function getDatasetKeyPrefix(): string
    {
        return 'znuny_prewarm_' . $this->datasetName;
    }

    protected function sanitizeError(string $error): string
    {
        return app(PrewarmErrorSanitizer::class)->sanitize($error);
    }

    // --- Protected hooks for testability ---

    protected function getCache(string $key)
    {
        return Cache::get($key);
    }

    protected function storeCache(string $key, $value, int $ttlMinutes): bool
    {
        return Cache::put($key, $value, now()->addMinutes($ttlMinutes));
    }

    protected function forgetCache(string $key): bool
    {
        return Cache::forget($key);
    }

    protected function acquireLock(string $key, int $seconds)
    {
        $lock = Cache::lock($key, $seconds);
        if ($lock->get()) {
            return $lock;
        }
        return false;
    }

    protected function releaseLock($lock): bool
    {
        return $lock ? $lock->release() : false;
    }
}
