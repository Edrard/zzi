<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Services\Zabbix\ZabbixClient;
use App\Services\Zabbix\ZabbixProblemCache;
use App\Services\Zabbix\ZabbixProblemFilterMatcher;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;

class PollZabbixProblems extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:poll-zabbix-problems {--force : Ignore poll interval and force polling now}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll Zabbix problems and store them in Redis cache';

    /**
     * Execute the console command.
     */
    public function handle(ZabbixClient $client, ZabbixProblemCache $cache): int
    {
        $pollIntervalMinutes = SettingsService::int('zabbix_poll_interval_minutes', 1) ?? 1;
        $ttlMinutes = SettingsService::int('zabbix_problem_cache_ttl_minutes', 3) ?? 3;
        $limit = SettingsService::int('zabbix_problem_limit', 100) ?? 100;

        $ttlSeconds = $ttlMinutes * 60;
        $pollIntervalSeconds = $pollIntervalMinutes * 60;

        $lastPoll = $cache->lastPoll();

        if (! $this->option('force') && $lastPoll && isset($lastPoll['status']) && $lastPoll['status'] === 'success') {
            $polledAt = isset($lastPoll['polled_at']) ? Carbon::parse($lastPoll['polled_at']) : null;
            if ($polledAt && $polledAt->diffInSeconds(now()) < $pollIntervalSeconds) {
                $this->info('Skipping poll. Last successful poll was too recent.');

                return self::SUCCESS;
            }
        }

        $this->info('Polling Zabbix problems...');

        try {
            $rawProblems = $client->getProblemsForPolling(['limit' => $limit]);
            $fetchedCount = count($rawProblems);

            $eventIds = array_column($rawProblems, 'eventid');
            $hostMap = $client->getEventHosts($eventIds);

            // Fetch host interfaces for IP enrichment
            $uniqueHostIds = [];
            foreach ($hostMap as $hosts) {
                foreach ($hosts as $h) {
                    if (isset($h['hostid'])) {
                        $uniqueHostIds[(string) $h['hostid']] = true;
                    }
                }
            }

            $interfacesMap = [];
            if (! empty($uniqueHostIds)) {
                try {
                    $interfacesResult = $client->getHostInterfaces(array_keys($uniqueHostIds));
                    foreach ($interfacesResult as $interface) {
                        if (isset($interface['hostid'])) {
                            $interfacesMap[(string) $interface['hostid']][] = $interface;
                        }
                    }
                } catch (Exception $e) {
                    $this->warn('Failed to fetch host interfaces for IP enrichment: '.$e->getMessage());
                }
            }

            // Fetch trigger map
            $triggerIds = [];
            foreach ($rawProblems as $p) {
                $tId = $p['objectid'] ?? $p['triggerid'] ?? null;
                if ($tId) {
                    $triggerIds[] = $tId;
                }
            }
            $triggerMap = $client->getTriggersForProblems($triggerIds);

            // Build active trigger map for dependency resolution
            $activeTriggers = [];
            foreach ($rawProblems as $p) {
                // Count as active only if unresolved
                if ((string) ($p['r_eventid'] ?? '0') === '0') {
                    $tId = $p['objectid'] ?? $p['triggerid'] ?? null;
                    if ($tId) {
                        $activeTriggers[(string) $tId] = true;
                    }
                }
            }

            $matcher = ZabbixProblemFilterMatcher::load();
            $excludeSuppressed = SettingsService::bool('zabbix_exclude_suppressed_problems', true);

            $cachedCount = 0;
            $excludedCount = 0;
            $disabledHostsExcludedCount = 0;
            $disabledTriggersExcludedCount = 0;
            $disabledItemsExcludedCount = 0;
            $dependencyCoveredExcludedCount = 0;
            $suppressedExcludedCount = 0;
            $normalizedProblems = [];

            $labels = [
                0 => 'Not classified',
                1 => 'Information',
                2 => 'Warning',
                3 => 'Average',
                4 => 'High',
                5 => 'Disaster',
            ];

            foreach ($rawProblems as $problem) {
                $eventId = $problem['eventid'];
                $severity = (int) ($problem['severity'] ?? 0);

                $eventHosts = $hostMap[$eventId] ?? [];

                $allHostsDisabled = false;
                if (! empty($eventHosts)) {
                    $allHostsDisabled = true;
                    foreach ($eventHosts as $h) {
                        if (! isset($h['status']) || (int) $h['status'] !== 1) {
                            $allHostsDisabled = false;
                            break;
                        }
                    }
                }

                if ($allHostsDisabled) {
                    $disabledHostsExcludedCount++;

                    continue;
                }

                $hostNames = [];
                foreach ($eventHosts as $h) {
                    $hostNames[] = $h['name'] ?? $h['host'] ?? $h['hostid'] ?? 'Unknown host';
                }

                $hostNameStr = empty($hostNames) ? 'Unknown host' : implode(', ', $hostNames);

                $hostIp = null;
                foreach ($eventHosts as $h) {
                    $hId = (string) ($h['hostid'] ?? '');
                    if (isset($interfacesMap[$hId])) {
                        $bestIp = null;
                        foreach ($interfacesMap[$hId] as $iface) {
                            $ip = trim((string) ($iface['ip'] ?? ''));
                            if ($ip === '') {
                                continue;
                            }

                            $isMain = ((string) ($iface['main'] ?? '0') === '1');
                            $isLoopback = ($ip === '127.0.0.1');

                            if ($bestIp === null) {
                                $bestIp = $ip;
                            }

                            if ($isMain && ! $isLoopback) {
                                $bestIp = $ip;
                                break;
                            }

                            if (! $isLoopback && $bestIp === '127.0.0.1') {
                                $bestIp = $ip;
                            }
                        }

                        if ($bestIp !== null) {
                            $hostIp = $bestIp;
                            break;
                        }
                    }
                }
                $clock = $problem['clock'] ?? null;
                $startedAt = $clock ? Carbon::createFromTimestamp($clock)->toIso8601String() : null;
                $ageSeconds = $clock ? max(0, time() - $clock) : 0;

                $triggerId = $problem['objectid'] ?? $problem['triggerid'] ?? null;
                $triggerData = $triggerId ? ($triggerMap[(string) $triggerId] ?? null) : null;

                if ($triggerData) {
                    if (isset($triggerData['status']) && (int) $triggerData['status'] === 1) {
                        $disabledTriggersExcludedCount++;

                        continue;
                    }

                    if (! empty($triggerData['items'])) {
                        $anyItemDisabled = false;
                        foreach ($triggerData['items'] as $item) {
                            if (isset($item['status']) && (int) $item['status'] === 1) {
                                $anyItemDisabled = true;
                                break;
                            }
                        }
                        if ($anyItemDisabled) {
                            $disabledItemsExcludedCount++;

                            continue;
                        }
                    }
                }

                $isDependencyCovered = false;
                if ($triggerData && ! empty($triggerData['dependencies'])) {
                    foreach ($triggerData['dependencies'] as $dep) {
                        $depId = $dep['triggerid'] ?? null;
                        if ($depId && isset($activeTriggers[(string) $depId])) {
                            $isDependencyCovered = true;
                            break;
                        }
                    }
                }

                if ($isDependencyCovered) {
                    $dependencyCoveredExcludedCount++;

                    continue;
                }

                $isSuppressed = (isset($problem['suppressed']) && (int) $problem['suppressed'] === 1);
                if ($excludeSuppressed && $isSuppressed) {
                    $suppressedExcludedCount++;

                    continue;
                }

                $normalized = [
                    'eventid' => $eventId,
                    'objectid' => $triggerId,
                    'triggerid' => $triggerId,
                    'name' => $problem['name'] ?? null,
                    'severity' => $severity,
                    'severity_label' => $labels[$severity] ?? 'Unknown',
                    'clock' => $clock,
                    'started_at' => $startedAt,
                    'age_seconds' => $ageSeconds,
                    'acknowledged' => $problem['acknowledged'] ?? null,
                    'suppressed' => $problem['suppressed'] ?? 0,
                    'r_eventid' => $problem['r_eventid'] ?? '0',
                    'tags' => $problem['tags'] ?? [],
                    'hosts' => $eventHosts,
                    'host_name' => $hostNameStr,
                    'host_ip' => $hostIp,
                    'trigger_status' => $triggerData['status'] ?? null,
                    'trigger_description' => $triggerData['description'] ?? null,
                    'trigger_items' => $triggerData['items'] ?? [],
                    'trigger_dependencies' => $triggerData['dependencies'] ?? [],
                    'cached_at' => now()->toIso8601String(),
                ];

                if ($matcher->exclude($normalized)) {
                    $excludedCount++;

                    continue;
                }

                $normalizedProblems[] = $normalized;
                $cachedCount++;
            }

            $cache->putMany($normalizedProblems, $ttlSeconds);
            $cache->markLastPollSuccess(
                $cachedCount,
                $ttlSeconds,
                $limit,
                $fetchedCount,
                $excludedCount,
                $disabledHostsExcludedCount,
                $disabledTriggersExcludedCount,
                $disabledItemsExcludedCount,
                $dependencyCoveredExcludedCount,
                $suppressedExcludedCount
            );

            $this->info("Fetched {$fetchedCount} problems from Zabbix.");
            $this->info("Excluded {$disabledHostsExcludedCount} problems from disabled hosts.");
            $this->info("Excluded {$disabledTriggersExcludedCount} problems from disabled triggers.");
            $this->info("Excluded {$disabledItemsExcludedCount} problems from disabled items.");
            $this->info("Excluded {$dependencyCoveredExcludedCount} dependency-covered problems.");
            $this->info("Excluded {$suppressedExcludedCount} suppressed problems.");
            $this->info("Excluded {$excludedCount} problems by filters.");
            $this->info("Successfully cached {$cachedCount} problems.");

            if ($fetchedCount === $limit) {
                $this->warn('Fetched problem count equals configured limit. There may be more non-suppressed problems in Zabbix.');
            }

            if ($lastPoll && isset($lastPoll['status']) && $lastPoll['status'] === 'failed') {
                AuditLogger::log(
                    'zabbix.problems_poll_recovered',
                    'system',
                    null,
                    ['cached_count' => $cachedCount, 'fetched_count' => $fetchedCount]
                );
                $this->info('Logged poll recovery to audit.');
            }

        } catch (Exception $e) {
            $errorMessage = $e->getMessage();

            $token = SettingsService::string('zabbix_api_token', '');
            if (! empty($token)) {
                $errorMessage = str_replace($token, '[redacted]', $errorMessage);
            }

            $errorMessage = preg_replace('/Bearer\s+[^\s]+/', 'Bearer [redacted]', $errorMessage) ?? $errorMessage;

            $this->error('Failed to poll Zabbix problems: '.$errorMessage);

            $cache->markLastPollFailure($errorMessage, $ttlSeconds);

            $wasPreviouslyFailed = $lastPoll && isset($lastPoll['status']) && $lastPoll['status'] === 'failed';

            if (! $wasPreviouslyFailed) {
                AuditLogger::log(
                    'zabbix.problems_poll_failed',
                    'system',
                    null,
                    ['error' => $errorMessage]
                );
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
