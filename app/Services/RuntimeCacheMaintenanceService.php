<?php

namespace App\Services;

use App\Services\Znuny\ZnunyAgentService;
use App\Services\Znuny\ZnunyCachedLookupService;
use App\Services\Znuny\ZnunyQueueService;
use App\Services\Znuny\ZnunyTicketArticleCacheService;

class RuntimeCacheMaintenanceService
{
    public function __construct(
        private SettingsService $settingsService,
        private ZnunyAgentService $agentService,
        private ZnunyQueueService $queueService,
        private ZnunyCachedLookupService $lookupService,
        private ZnunyTicketArticleCacheService $articleCacheService
    ) {}

    public function clearSettingsCache(): void
    {
        $this->settingsService->clearAllCaches();
    }

    public function clearZnunyAgentCache(): void
    {
        $this->agentService->clearCache();
    }

    public function clearZnunyQueueCache(): void
    {
        $this->queueService->clearCache();
    }

    public function clearZnunyLookupCache(): void
    {
        $this->lookupService->clearCache();
    }

    public function clearTicketArticleCache(): void
    {
        $this->articleCacheService->forgetAll();
    }
}
