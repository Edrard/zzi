<?php

namespace App\Services\Znuny\Cache;

class ZnunyQueueCacheReadService
{
    private PrewarmSnapshotManager $snapshotManager;

    public function __construct()
    {
        $this->snapshotManager = new PrewarmSnapshotManager('queues');
    }

    /**
     * Get the coherent snapshot (generation, payload, metadata).
     * Returns null if missing or expired.
     */
    public function getSnapshot(): ?array
    {
        return $this->snapshotManager->readActiveSnapshot();
    }

    /**
     * Return the active cached queue dataset.
     * Never calls Znuny on cache miss. Returns an explicit empty result instead.
     */
    public function getQueues(): array
    {
        $snapshot = $this->getSnapshot();
        return (isset($snapshot['payload']) && is_array($snapshot['payload'])) ? $snapshot['payload'] : [];
    }

    /**
     * Expose metadata/status separately.
     */
    public function getMetadata(): array
    {
        return $this->snapshotManager->readMetadata();
    }
}
