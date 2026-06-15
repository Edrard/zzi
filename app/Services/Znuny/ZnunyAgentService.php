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
     * Get active agents excluding technical/service logins.
     * Use this for future manual ticket creation modals and ticket owner selection.
     */
    public function getSelectableAgents(bool $failSilently = true, bool $forceRefresh = false): array
    {
        $agents = $this->getAgents($failSilently, $forceRefresh);

        if (empty($agents)) {
            return [];
        }

        $excludedSetting = SettingsService::string('znuny_agent_exclude_logins', '');
        $lines = explode("\n", $excludedSetting);

        $excludedLogins = collect($lines)
            ->map(fn ($line) => trim($line))
            ->filter(fn ($line) => $line !== '')
            ->map(fn ($line) => strtolower($line))
            ->all();

        return array_values(array_filter($agents, function ($agent) use ($excludedLogins) {
            return ! in_array(strtolower($agent['login']), $excludedLogins, true);
        }));
    }
}
