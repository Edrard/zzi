<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Services\Zabbix\ZabbixClient;
use App\Services\Zabbix\ZabbixProblemCache;
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
            $problems = $client->getProblems(['limit' => $limit]);
            $problemCount = count($problems);

            $cache->putMany($problems, $ttlSeconds);
            $cache->markLastPollSuccess($problemCount, $ttlSeconds, $limit);

            $this->info("Successfully fetched and cached {$problemCount} problems.");

            if ($problemCount === $limit) {
                $this->warn('Fetched problem count equals configured limit. There may be more non-suppressed problems in Zabbix.');
            }

            if ($lastPoll && isset($lastPoll['status']) && $lastPoll['status'] === 'failed') {
                AuditLogger::log(
                    action: 'zabbix.problems_poll_recovered',
                    entityType: 'system',
                    entityId: null,
                    context: ['problem_count' => $problemCount]
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
                    action: 'zabbix.problems_poll_failed',
                    entityType: 'system',
                    entityId: null,
                    context: ['error' => $errorMessage]
                );
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
