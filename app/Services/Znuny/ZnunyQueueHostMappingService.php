<?php

namespace App\Services\Znuny;

use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\Zabbix\ZabbixProblemCache;

class ZnunyQueueHostMappingService
{
    public function __construct(
        protected ZnunyQueueService $queueService,
        protected ZnunyTicketDefaultRuleService $ruleService,
        protected ZabbixProblemCache $problemCache
    ) {}

    public function normalizeMappings(array $rawMappings): array
    {
        $normalized = [];
        $seen = [];

        foreach ($rawMappings as $row) {
            $prefix = trim($row['host_prefix'] ?? '');
            $queue = trim($row['queue_name'] ?? '');
            $note = trim($row['note'] ?? '');

            if ($prefix === '' || $queue === '') {
                continue;
            }

            $lower = strtolower($prefix);
            if (isset($seen[$lower])) {
                continue;
            }

            $seen[$lower] = true;
            $normalized[] = [
                'host_prefix' => $prefix,
                'queue_name' => $queue,
                'note' => $note,
            ];
        }

        return $normalized;
    }

    public function saveMappings(array $rawMappings): void
    {
        $normalized = $this->normalizeMappings($rawMappings);

        $setting = Setting::firstOrNew(['key' => 'znuny_queue_host_mappings']);
        $oldValue = $setting->exists ? $setting->value : null;

        $setting->value = json_encode($normalized);
        $setting->type = 'json';
        $setting->save();

        AuditLogger::log(
            'settings.updated',
            'settings',
            null,
            [
                'changes' => [[
                    'key' => 'znuny_queue_host_mappings',
                    'old_value' => $oldValue,
                    'new_value' => json_encode($normalized),
                ]],
            ]
        );
    }

    public function scanMissingMappings(array $currentMappings): array
    {
        $problems = $this->problemCache->all();

        $missingDrafts = [];
        $uniquePrefixes = [];
        $stats = [
            'scanned' => count($problems),
            'unique_prefixes' => 0,
            'added' => 0,
            'skipped_existing_queue' => 0,
            'skipped_existing_mapping' => 0,
            'failed_api' => 0,
        ];

        $existingPrefixes = collect($currentMappings)
            ->map(fn ($m) => strtolower(trim($m['host_prefix'] ?? '')))
            ->filter()
            ->toArray();

        $existingZnunyQueues = [];
        try {
            $queues = $this->queueService->getQueues();
            $existingZnunyQueues = collect($queues)->pluck('name')->map(fn ($n) => strtolower($n))->toArray();
        } catch (\Throwable $e) {
            $stats['failed_api']++;
        }

        foreach ($problems as $problem) {
            $hostName = $problem['hosts'][0]['name'] ?? null;
            if (! $hostName) {
                continue;
            }

            $prefix = $this->ruleService->detectQueueFromHost($hostName);
            if (! $prefix) {
                continue;
            }

            $lowerPrefix = strtolower(trim($prefix));
            if (! in_array($lowerPrefix, $uniquePrefixes)) {
                $uniquePrefixes[] = $lowerPrefix;
                $stats['unique_prefixes']++;

                if (in_array($lowerPrefix, $existingPrefixes)) {
                    $stats['skipped_existing_mapping']++;

                    continue;
                }

                if (in_array($lowerPrefix, $existingZnunyQueues)) {
                    $stats['skipped_existing_queue']++;
                } else {
                    $missingDrafts[] = [
                        'host_prefix' => trim($prefix),
                        'queue_name' => '',
                        'note' => 'Detected from current Zabbix problems',
                    ];
                    $stats['added']++;
                    $existingPrefixes[] = $lowerPrefix;
                }
            }
        }

        return [
            'drafts' => $missingDrafts,
            'stats' => $stats,
        ];
    }
}
