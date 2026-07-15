<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;

class ZnunyQueueService
{
    private const QUEUE_CACHE_KEY = 'znuny.queues';

    public function __construct(private ZnunyClient $client) {}

    private function getCacheTtlMinutes(): int
    {
        // 0 is valid and means bypass persistent cache.
        // Negative, missing, or unreadable values fall back to 15.
        $ttl = SettingsService::int('znuny_queue_cache_ttl_minutes', 15);

        return $ttl >= 0 ? $ttl : 15;
    }

    /**
     * Clears the cached queues exactly.
     */
    public function clearCache(): void
    {
        Cache::forget(self::QUEUE_CACHE_KEY);
    }

    public function getQueues(): array
    {
        $ttl = $this->getCacheTtlMinutes();

        if ($ttl === 0) {
            return $this->client->getQueues();
        }

        return Cache::remember(self::QUEUE_CACHE_KEY, now()->addMinutes($ttl), function () {
            return $this->client->getQueues();
        });
    }

    public function getSelectableQueuesResult(): array
    {
        try {
            $queues = $this->getQueues();

            // Transform first, then filter by UI rules
            $options = collect($queues)->mapWithKeys(function ($queue) {
                $name = $queue['name'] ?? '';
                $label = $queue['label'] ?? $queue['full_name'] ?? $name;

                return [$name => $label];
            })->filter(fn ($value, $key) => $key !== '')->toArray();

            $options = app(ZnunyUiFilterService::class)->filterQueuesForUi($options);

            return [
                'options' => $options,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'options' => [],
                'error' => 'Could not load queues from Znuny API. You can try again later.',
            ];
        }
    }

    public function findQueueByName(string $name): array
    {
        try {
            $queues = $this->getQueues();
            $lowerName = strtolower($name);

            $foundQueue = collect($queues)->first(function ($queue) use ($lowerName) {
                return strtolower($queue['name'] ?? '') === $lowerName;
            });

            if ($foundQueue) {
                $qName = $foundQueue['name'] ?? '';
                $fullName = $foundQueue['full_name'] ?? $qName;

                return [
                    'found' => true,
                    'id' => $foundQueue['id'] ?? null,
                    'name' => $qName,
                    'full_name' => $fullName,
                    'valid_id' => $foundQueue['valid_id'] ?? 1,
                    'label' => $foundQueue['label'] ?? $fullName,
                    'warnings' => [],
                ];
            }

            return ['found' => false, 'warnings' => ['Queue not found.']];
        } catch (\Throwable $e) {
            return ['found' => false, 'warnings' => ['Could not load queues from Znuny API.']];
        }
    }
}
