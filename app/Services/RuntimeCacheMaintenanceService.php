<?php

namespace App\Services;

use App\Services\Znuny\ZnunyTicketArticleCacheService;

class RuntimeCacheMaintenanceService
{
    public function __construct(
        private ZnunyTicketArticleCacheService $articleCacheService
    ) {}

    public function clearTicketArticleCache(): void
    {
        $this->articleCacheService->forgetAll();
    }
}
