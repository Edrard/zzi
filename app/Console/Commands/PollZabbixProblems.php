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
            $rawProblems = $client->getProblems(['limit' => $limit]);
            $fetchedCount = count($rawProblems);

            $eventIds = array_column($rawProblems, 'eventid');
            $hostMap = $client->getEventHosts($eventIds);

            $matcher = ZabbixProblemFilterMatcher::load();

            $cachedCount = 0;
            $excludedCount = 0;
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
                $hostNames = [];
                foreach ($eventHosts as $h) {
                    $hostNames[] = $h['name'] ?? $h['host'] ?? $h['hostid'] ?? 'Unknown host';
                }

                $hostNameStr = empty($hostNames) ? 'Unknown host' : implode(', ', $hostNames);
                $clock = $problem['clock'] ?? null;
                $startedAt = $clock ? Carbon::createFromTimestamp($clock)->toIso8601String() : null;
                $ageSeconds = $clock ? max(0, time() - $clock) : 0;

                $normalized = [
                    'eventid' => $eventId,
                    'objectid' => $problem['objectid'] ?? $problem['triggerid'] ?? null,
                    'name' => $problem['name'] ?? null,
                    'severity' => $severity,
                    'severity_label' => $labels[$severity] ?? 'Unknown',
                    'clock' => $clock,
                    'started_at' => $startedAt,
                    'age_seconds' => $ageSeconds,
                    'acknowledged' => $problem['acknowledged'] ?? null,
                    'tags' => $problem['tags'] ?? [],
                    'hosts' => $eventHosts,
                    'host_name' => $hostNameStr,
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
            $cache->markLastPollSuccess($cachedCount, $ttlSeconds, $limit, $fetchedCount, $excludedCount);

            $this->info("Fetched {$fetchedCount} problems from Zabbix.");
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
