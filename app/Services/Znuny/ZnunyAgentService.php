<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;
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
        $excludedSetting = SettingsService::string('znuny_agent_exclude_logins', '');
        $lines = explode("\n", $excludedSetting);

        return collect($lines)
            ->map(fn ($line) => trim($line))
            ->filter(fn ($line) => $line !== '')
            ->map(fn ($line) => strtolower($line))
            ->all();
    }

    public function isLoginExcluded(?string $login): bool
    {
        if (empty($login)) {
            return false;
        }

        return in_array(strtolower($login), $this->excludedLogins(), true);
    }

    public function filterSelectableAgents(array $agents): array
    {
        $excludedLogins = $this->excludedLogins();

        return array_values(array_filter($agents, function ($agent) use ($excludedLogins) {
            $login = $agent['login'] ?? null;
            if ($login === null) {
                return true;
            }

            return ! in_array(strtolower($login), $excludedLogins, true);
        }));
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
