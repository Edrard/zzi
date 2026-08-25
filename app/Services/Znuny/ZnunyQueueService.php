<?php

namespace App\Services\Znuny;

use App\Services\Znuny\Cache\ZnunyQueueCacheReadService;

class ZnunyQueueService
{
    public function __construct(
        private ZnunyQueueCacheReadService $queueReader
    ) {}

    public function getQueues(): array
    {
        return $this->queueReader->getQueues();
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
                'error' => 'Could not load prewarmed Znuny queue reference data. You can try again later.',
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
            return ['found' => false, 'warnings' => ['Could not load prewarmed Znuny queue reference data.']];
        }
    }
}
