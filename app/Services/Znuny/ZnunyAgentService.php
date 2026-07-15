<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ZnunyAgentService
{
    private const CACHE_KEY = 'znuny_active_agents';

    private ?string $lastError = null;

    public function __construct(private ZnunyClient $client) {}

    /**
     * Get the last error encountered during agent fetching.
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    private function getCacheTtlMinutes(): int
    {
        // 0 is valid and means bypass persistent cache.
        // Negative, missing, or unreadable values fall back to 15.
        $ttl = SettingsService::int('znuny_agent_cache_ttl_minutes', 15);

        return $ttl >= 0 ? $ttl : 15;
    }

    /**
     * Get active agents from cache or fetch from API if not cached.
     * On failure, it suppresses exception and returns an empty array to prevent crashing UI,
     * but you can pass $failSilently = false to throw it.
     */
    public function getAgents(bool $failSilently = true, bool $forceRefresh = false): array
    {
        $this->lastError = null;
        $ttl = $this->getCacheTtlMinutes();

        if ($forceRefresh) {
            Cache::forget(self::CACHE_KEY);
        }

        try {
            if ($ttl === 0) {
                return $this->client->getAgents();
            }

            return Cache::remember(self::CACHE_KEY, now()->addMinutes($ttl), function () {
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

    /**
     * Build a lookup map of OwnerID → display name from the cached agent list.
     *
     * @return array<int, string> keyed by agent UserID
     */
    public function getAgentNameMap(): array
    {
        $agents = $this->getAgents(failSilently: true);

        $map = [];
        foreach ($agents as $agent) {
            $id = $agent['id'] ?? null;
            if ($id === null) {
                continue;
            }
            $map[(int) $id] = $agent['name'] ?? $agent['login'] ?? ('Agent '.$id);
        }

        return $map;
    }

    public function excludedLogins(): array
    {
        return app(ZnunyUiFilterService::class)->getExcludedAgentLogins();
    }

    public function isLoginExcluded(?string $login): bool
    {
        return app(ZnunyUiFilterService::class)->isAgentLoginExcluded($login);
    }

    public function filterSelectableAgents(array $agents): array
    {
        return array_values(app(ZnunyUiFilterService::class)->filterAgentsForUi($agents));
    }

    /**
     * Get active agents excluding technical/service logins.
     * Use this for future manual ticket creation modals and ticket owner selection.
     */
    public function getSelectableAgents(bool $failSilently = true, bool $forceRefresh = false): array
    {
        $agents = $this->getAgents($failSilently, $forceRefresh);

        $validAgents = array_filter($agents, fn ($agent) => ($agent['valid_id'] ?? 1) === 1);

        return $this->filterSelectableAgents($validAgents);
    }

    public function getSelectableAssignableAgentsForQueue(int|string $queueId, bool $failSilently = true): array
    {
        $this->lastError = null;

        try {
            $agents = $this->client->getQueueAssignableAgents($queueId);

            return $this->filterSelectableAgents($agents);
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::error('Failed to fetch Znuny assignable agents for queue: '.$e->getMessage());

            if (! $failSilently) {
                throw $e;
            }

            return [];
        }
    }
}
