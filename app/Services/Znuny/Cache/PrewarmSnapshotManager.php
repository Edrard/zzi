<?php

namespace App\Services\Znuny\Cache;

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
     * Get the active payload, or null if none is ready.
     */
    public function readActive(): ?array
    {
        $meta = $this->readMetadata();

        if (empty($meta['active_generation'])) {
            return null;
        }

        return Cache::get($meta['active_generation']);
    }

    /**
     * Get the current metadata for the dataset.
     */
    public function readMetadata(): array
    {
        return Cache::get($this->getMetaKey(), [
            'dataset_name' => $this->datasetName,
            'status' => 'missing',
            'active_generation' => null,
            'item_count' => 0,
            'last_attempt_at' => null,
            'last_successful_refresh_at' => null,
            'last_error' => null,
            'refresh_source' => null,
        ]);
    }

    /**
     * Refresh the snapshot atomically.
     *
     * @param Closure $fetcher Should return the normalized array payload. Throws on failure.
     * @param string $source The source of the refresh (e.g. 'artisan', 'scheduler')
     * @return bool True if successful, false if failed.
     */
    public function refresh(Closure $fetcher, string $source = 'manual'): bool
    {
        $lock = Cache::lock($this->getLockKey(), 120);

        if (! $lock->get()) {
            return false;
        }

        $meta = $this->readMetadata();
        $meta['last_attempt_at'] = now()->toIso8601String();
        $meta['status'] = 'refreshing';
        $meta['refresh_source'] = $source;
        $this->saveMetadata($meta);

        $tempKey = $this->getDatasetKeyPrefix() . '_v' . time() . '_' . uniqid();

        try {
            $payload = $fetcher();

            if (! is_array($payload)) {
                throw new \Exception('Fetcher returned non-array payload.');
            }

            // Write temporary snapshot
            Cache::forever($tempKey, $payload);

            // Validation passed, swap metadata
            $oldGeneration = $meta['active_generation'];

            $meta['active_generation'] = $tempKey;
            $meta['status'] = 'ready';
            $meta['item_count'] = count($payload);
            $meta['last_successful_refresh_at'] = now()->toIso8601String();
            $meta['last_error'] = null;
            
            $this->saveMetadata($meta);

            // Cleanup old snapshot
            if ($oldGeneration && $oldGeneration !== $tempKey) {
                Cache::forget($oldGeneration);
            }

            $lock->release();

            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to refresh prewarm dataset [{$this->datasetName}]: " . $e->getMessage());

            // Remove temporary snapshot if it exists
            Cache::forget($tempKey);

            $meta = $this->readMetadata();
            $meta['status'] = $meta['active_generation'] ? 'stale' : 'failed';
            $meta['last_error'] = $this->sanitizeError($e->getMessage());
            $this->saveMetadata($meta);

            $lock->release();

            return false;
        }
    }

    protected function saveMetadata(array $meta): void
    {
        Cache::forever($this->getMetaKey(), $meta);
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

    private function sanitizeError(string $error): string
    {
        $keys = 'password|token|secret|api_key|apikey|access_token|refresh_token|client_secret|authorization';
        $error = preg_replace(
            '/(' . $keys . ')([ "\']*[=:][ "\']*(?:Bearer\s+)?)([^\s&"\'\r\n]+)/i',
            '$1$2***',
            $error
        );

        if (($pos = strpos($error, 'Stack trace:')) !== false) {
            $error = substr($error, 0, $pos);
        }

        return substr(trim($error), 0, 500);
    }
}
