<?php

namespace App\Services\Zabbix;

use Illuminate\Support\Facades\Redis;

class ZabbixProblemCache
{
    /**
     * Store normalized problems in Redis.
     *
     * @param  array<int, array<string, mixed>>  $problems
     * @param  int  $ttl  Seconds
     */
    public function putMany(array $problems, int $ttl): void
    {
        if (empty($problems)) {
            $this->updateIndex([], $ttl);

            return;
        }

        $eventIds = [];

        Redis::pipeline(function ($pipe) use ($problems, $ttl, &$eventIds) {
            foreach ($problems as $problem) {
                $eventId = $problem['eventid'];
                $eventIds[] = $eventId;

                $key = "zabbix:problem:{$eventId}";
                $pipe->setex($key, $ttl, json_encode($problem, JSON_THROW_ON_ERROR));
            }
        });

        $this->updateIndex($eventIds, $ttl);
    }

    /**
     * @param  array<int, string|int>  $eventIds
     * @param  int  $ttl  Seconds
     */
    protected function updateIndex(array $eventIds, int $ttl): void
    {
        Redis::setex('zabbix:problems:index', $ttl, json_encode($eventIds, JSON_THROW_ON_ERROR));
    }

    /**
     * Retrieve all problems from cache.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $indexJson = Redis::get('zabbix:problems:index');

        if (! $indexJson) {
            return [];
        }

        try {
            $eventIds = json_decode($indexJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [];
        }

        if (! is_array($eventIds) || empty($eventIds)) {
            return [];
        }

        $keys = array_map(fn ($id) => "zabbix:problem:{$id}", $eventIds);
        $problemsJson = Redis::mget($keys);

        $problems = [];
        foreach ($problemsJson as $json) {
            if ($json) {
                try {
                    $problems[] = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    continue;
                }
            }
        }

        return $problems;
    }

    /**
     * Find a specific problem by event ID.
     *
     * @return array<string, mixed>|null
     */
    public function find(string|int $eventId): ?array
    {
        $json = Redis::get("zabbix:problem:{$eventId}");

        if (! $json) {
            return null;
        }

        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
    }

    /**
     * Get the last poll status.
     *
     * @return array<string, mixed>|null
     */
    public function lastPoll(): ?array
    {
        $json = Redis::get('zabbix:problems:last_poll');

        if (! $json) {
            return null;
        }

        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
    }

    /**
     * Mark the last poll as successful.
     *
     * @param  int  $ttl  Seconds
     */
    public function markLastPollSuccess(int $problemCount, int $ttl, ?int $limit = null, int $fetchedCount = 0, int $excludedCount = 0): void
    {
        $payload = [
            'status' => 'success',
            'cached_count' => $problemCount,
            'fetched_count' => $fetchedCount,
            'excluded_count' => $excludedCount,
            'polled_at' => now()->toIso8601String(),
            'ttl_minutes' => (int) round($ttl / 60),
            'ttl_seconds' => $ttl,
        ];

        if ($limit !== null) {
            $payload['limit'] = $limit;
        }

        Redis::setex('zabbix:problems:last_poll', $ttl, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Mark the last poll as failed.
     *
     * @param  int  $ttl  Seconds
     */
    public function markLastPollFailure(string $error, int $ttl): void
    {
        $lastPoll = $this->lastPoll();

        $payload = [
            'status' => 'failed',
            'polled_at' => now()->toIso8601String(),
            'error' => $error,
        ];

        if ($lastPoll && isset($lastPoll['status'])) {
            $payload['previous_status'] = $lastPoll['status'];
        }

        Redis::setex('zabbix:problems:last_poll', $ttl, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Clear all Zabbix cache keys.
     */
    public function clear(): void
    {
        $keys = array_merge(
            Redis::keys('zabbix:problems:*'),
            Redis::keys('zabbix:problem:*')
        );

        if (! empty($keys)) {
            // Redis::keys returns keys with prefix, which del() might double-prefix depending on config
            // However, typical Laravel setup using Phpredis handles this transparently via Mget/Del
            // or we strip prefix. Using generic loop is safest.
            foreach ($keys as $key) {
                $stripped = preg_replace('/^'.preg_quote(config('database.redis.options.prefix', ''), '/').'/', '', $key);
                Redis::del($stripped);
            }
        }
    }
}
