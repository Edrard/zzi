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
     * Return the active cached queue dataset.
     * Never calls Znuny on cache miss. Returns an explicit empty result instead.
     */
    public function getQueues(): array
    {
        return $this->snapshotManager->readActive() ?? [];
    }

    /**
     * Expose metadata/status separately.
     */
    public function getMetadata(): array
    {
        return $this->snapshotManager->readMetadata();
    }
}
