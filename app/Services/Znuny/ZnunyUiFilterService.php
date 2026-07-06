<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;

class ZnunyUiFilterService
{
    protected ?array $excludedLoginsCache = null;

    protected ?array $exclusionRegexesCache = null;

    /**
     * Get the excluded agent logins as an array of lowercase strings.
     */
    public function getExcludedAgentLogins(): array
    {
        if ($this->excludedLoginsCache !== null) {
            return $this->excludedLoginsCache;
        }

        $setting = SettingsService::string('znuny_agent_exclude_logins', '');

        $logins = array_filter(
            array_map(
                fn ($line) => strtolower(trim($line)),
                explode("\n", $setting)
            ),
            fn ($line) => $line !== ''
        );

        $this->excludedLoginsCache = $logins;

        return $this->excludedLoginsCache;
    }

    /**
     * Check if a specific agent login is excluded.
     */
    public function isAgentLoginExcluded(?string $login): bool
    {
        if (blank($login)) {
            return false;
        }

        return in_array(strtolower(trim($login)), $this->getExcludedAgentLogins(), true);
    }

    /**
     * Filter an array of agent data arrays (which contain 'login').
     * Keeps only agents that are NOT excluded.
     */
    public function filterAgentsForUi(array $agents): array
    {
        return array_filter($agents, function ($agent) {
            $login = $agent['login'] ?? null;
            if ($login === null) {
                // If there's no login, don't exclude it by name.
                return true;
            }

            return ! $this->isAgentLoginExcluded($login);
        });
    }

    /**
     * Filter an options array [login => displayName].
     * Can optionally use $agentsContext if you have full agent arrays to look up login by something else,
     * but usually the key is the login or the value contains it.
     * We assume the array key is the login or the value is a string and the key is the login.
     * In Znuny, options typically have the login as the key.
     */
    public function filterOwnerOptionsForUi(array $options, array $agentsContext = []): array
    {
        // If agentsContext is provided, it maps ID -> Agent Array or similar, but the option key is typically Login or ID.
        // Usually, options are [login => 'Full Name'] or [id => 'Full Name (login)'].
        // Let's filter by checking if the key is an excluded login, or if we can extract it from the agent context.

        $filtered = [];

        // Build a lookup from agentsContext if available (e.g. ['login' => '...', 'id' => '...'])
        $idToLogin = [];
        foreach ($agentsContext as $agent) {
            if (isset($agent['id'], $agent['login'])) {
                $idToLogin[$agent['id']] = $agent['login'];
            }
        }

        foreach ($options as $key => $value) {
            $login = $key;

            // If the key is numeric (agent ID), try to resolve login from context
            if (is_numeric($key) && isset($idToLogin[$key])) {
                $login = $idToLogin[$key];
            }
            // Sometimes options are formatted as 'Login' => 'First Last'
            // Let's just check the key directly if it's not numeric or if we couldn't resolve it.

            if (! $this->isAgentLoginExcluded((string) $login)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Get the queue exclusion regexes.
     */
    public function getQueueExclusionRegexes(): array
    {
        if ($this->exclusionRegexesCache !== null) {
            return $this->exclusionRegexesCache;
        }

        $setting = SettingsService::json('znuny_global_queue_exclusion_regexes', []);

        $regexes = [];
        foreach ($setting as $item) {
            $regex = '';
            if (is_string($item)) {
                $regex = trim($item);
            } elseif (is_array($item)) {
                $regex = trim($item['regex'] ?? '');
            }

            if ($regex === '') {
                continue;
            }

            // Validate the regex
            $pattern = '~'.str_replace('~', '\~', $regex).'~iu';
            if (@preg_match($pattern, '') !== false) {
                $regexes[] = $pattern;
            } else {
                Log::warning("Invalid Znuny global queue exclusion regex ignored: {$regex}");
            }
        }

        $this->exclusionRegexesCache = $regexes;

        return $this->exclusionRegexesCache;
    }

    /**
     * Check if a specific queue is excluded based on its Name or FullName.
     */
    public function isQueueExcluded(string $name, ?string $fullName = null): bool
    {
        $regexes = $this->getQueueExclusionRegexes();

        if (empty($regexes)) {
            return false;
        }

        foreach ($regexes as $pattern) {
            if (preg_match($pattern, $name)) {
                return true;
            }
            if ($fullName !== null && preg_match($pattern, $fullName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filter an array of queue data arrays or queue options.
     * If elements are arrays with 'Name' and 'FullName', we use those.
     * If elements are strings, we treat the value as the Name/FullName and key as Queue ID/Name.
     */
    public function filterQueuesForUi(array $queues): array
    {
        $filtered = [];

        foreach ($queues as $key => $queue) {
            if (is_array($queue)) {
                $name = $queue['name'] ?? $queue['Name'] ?? '';
                $fullName = $queue['full_name'] ?? $queue['FullName'] ?? $queue['label'] ?? $queue['Label'] ?? null;

                if (! $this->isQueueExcluded($name, $fullName)) {
                    $filtered[$key] = $queue;
                }
            } else {
                // $queue is a string (the display name/label)
                $name = is_string($key) ? $key : '';
                $label = (string) $queue;

                // For option arrays, check both key as queue name and value as full name/label
                if (! $this->isQueueExcluded($name, $label) && ! $this->isQueueExcluded($label, null)) {
                    $filtered[$key] = $queue;
                }
            }
        }

        return $filtered;
    }
}
