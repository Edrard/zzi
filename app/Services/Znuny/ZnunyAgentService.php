<?php

namespace App\Services\Znuny;

use App\Services\Znuny\Cache\ZnunyAgentCacheReadService;
use Illuminate\Support\Facades\Log;
use Throwable;

class ZnunyAgentService
{
    private ?string $lastError = null;

    public function __construct(private ZnunyAgentCacheReadService $agentReader) {}

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

        try {
            return $this->agentReader->getAgents();
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
            $agentIds = $this->agentReader->getAgentIdsForQueue((int) $queueId);
            $agents = $this->agentReader->getAgents();

            $assignableAgents = [];
            foreach ($agentIds as $id) {
                foreach ($agents as $agent) {
                    if (($agent['id'] ?? null) === $id) {
                        $assignableAgents[] = $agent;
                        break;
                    }
                }
            }

            return $this->filterSelectableAgents($assignableAgents);
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
