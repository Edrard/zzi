<?php

namespace App\Services\Znuny;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ZnunyAgentService
{
    private const CACHE_KEY = 'znuny_active_agents';

    private const CACHE_TTL = 900; // 15 minutes

    private ?string $lastError = null;

    public function __construct(private ZnunyClient $client) {}

    /**
     * Get the last error encountered during agent fetching.
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get active agents from cache or fetch from API if not cached.
     * On failure, it suppresses exception and returns an empty array to prevent crashing UI,
     * but you can pass $failSilently = false to throw it.
     */
    public function getAgents(bool $failSilently = true, bool $forceRefresh = false): array
    {
        $this->lastError = null;

        if ($forceRefresh) {
            Cache::forget(self::CACHE_KEY);
        }

        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
                return $this->client->getAgents();
            });
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::error('Failed to fetch Znuny agents: '.$e->getMessage());

            if (! $failSilently) {
                throw $e;
            }

            return [];
        }
    }
}
