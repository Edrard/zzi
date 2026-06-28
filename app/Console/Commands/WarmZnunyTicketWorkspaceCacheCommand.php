<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketCacheService;
use App\Services\Znuny\ZnunyTicketWorkspaceStateTypeMapper;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Throwable;

class WarmZnunyTicketWorkspaceCacheCommand extends Command
{
    protected $signature = 'znuny:warm-ticket-workspace-cache {--scheduled} {--manual : Indicates the cache warm was triggered manually by an operator}';

    protected $description = 'Warm up the Ticket Workspace cache by polling active tickets from Znuny';

    public function handle(
        ZnunyClient $client,
        ZnunyTicketCacheService $cacheService,
        ZnunyTicketWorkspaceStateTypeMapper $mapper
    ): int {
        $enabled = SettingsService::bool('znuny_ticket_workspace_enabled', false) ?? false;
        $isManual = (bool) $this->option('manual');
        $shouldAudit = $isManual || SettingsService::bool('znuny_ticket_workspace_sync_audit_enabled', false);

        if (! $enabled) {
            $this->info('Ticket Workspace is disabled in settings. Exiting cleanly.');

            if ($isManual) {
                AuditLogger::log('znuny.ticket_workspace_sync.skipped', 'system', null, [
                    'source' => 'manual', 'manual' => true, 'scheduled' => false, 'reason' => 'disabled',
                ]);
            }

            return self::SUCCESS;
        }

        if ($this->option('scheduled')) {
            $interval = SettingsService::int('znuny_ticket_cache_refresh_interval_minutes', 5) ?? 5;
            if ($interval <= 0) {
                $interval = 5;
            }

            $lastWarmAt = Redis::get('znuny:ticket_workspace:last_warm_at');
            if ($lastWarmAt) {
                $dueAt = Carbon::createFromTimestamp($lastWarmAt)->addMinutes($interval);
                if (Carbon::now()->lessThan($dueAt)) {
                    $this->info('Scheduled warmer is not due yet. Exiting cleanly.');

                    return self::SUCCESS;
                }
            }
        }

        $activeStateTypeIdsJson = SettingsService::string('znuny_ticket_workspace_active_state_type_ids', '[]');
        $activeStateTypeIds = json_decode($activeStateTypeIdsJson, true) ?? [];

        if (empty($activeStateTypeIds) || ! is_array($activeStateTypeIds)) {
            $this->info('No active state type IDs configured. Exiting.');

            if ($isManual) {
                AuditLogger::log('znuny.ticket_workspace_sync.skipped', 'system', null, [
                    'source' => 'manual', 'manual' => true, 'scheduled' => false, 'reason' => 'no_active_state_types',
                ]);
            }

            return self::SUCCESS;
        }

        $mappedStateTypes = $mapper->mapInternalIdsToZnunyTypes($activeStateTypeIds);

        if (empty($mappedStateTypes)) {
            $this->info('No matching Znuny StateTypes found for the configured IDs. Exiting.');

            if ($isManual) {
                AuditLogger::log('znuny.ticket_workspace_sync.skipped', 'system', null, [
                    'source' => 'manual', 'manual' => true, 'scheduled' => false, 'reason' => 'no_mapped_state_types',
                ]);
            }

            return self::SUCCESS;
        }

        $limit = SettingsService::int('znuny_ticket_cache_default_limit', 50) ?? 50;
        $maxPages = SettingsService::int('znuny_ticket_cache_max_pages_per_run', 3) ?? 3;

        $combinedStateTypes = implode(',', $mappedStateTypes);
        $this->info("Warming cache for StateTypes: {$combinedStateTypes}");

        $counters = [
            'state_types' => count($mappedStateTypes),
            'total_count' => 0,
            'count_only_requests' => 0,
            'pages_requested' => 0,
            'tickets_seen' => 0,
            'cached_new' => 0,
            'refreshed_unchanged' => 0,
            'updated_changed' => 0,
            'skipped_missing_ticket_id' => 0,
            'skipped_disabled' => 0,
            'errors' => 0,
            'warnings' => 0,
        ];

        try {
            $counters['count_only_requests']++;
            $metadata = $client->searchTicketsWithMetadata([
                'StateType' => $combinedStateTypes,
                'CountOnly' => 1,
            ]);

            if (! empty($metadata['warnings'])) {
                foreach ($metadata['warnings'] as $warning) {
                    $this->warn("Znuny Warning: {$warning}");
                    $counters['warnings']++;
                }
            }

            $totalCount = $metadata['total_count'] ?? 0;
            $counters['total_count'] = $totalCount;

            if ($totalCount === 0) {
                $this->info('No active tickets found. Exiting cleanly.');
            } else {
                $this->info("Total active tickets found: {$totalCount}");

                $offset = 0;
                $pagesRequested = 0;

                while ($offset < $totalCount && $pagesRequested < $maxPages) {
                    $filters = [
                        'StateType' => $combinedStateTypes,
                        'Limit' => $limit,
                        'Offset' => $offset,
                        'SortBy' => 'Changed',
                        'SortDirection' => 'DESC',
                    ];

                    $counters['pages_requested']++;
                    $pagesRequested++;
                    $pageMetadata = $client->searchTicketsWithMetadata($filters);

                    if (! empty($pageMetadata['warnings'])) {
                        foreach ($pageMetadata['warnings'] as $warning) {
                            $this->warn("Znuny Warning: {$warning}");
                            $counters['warnings']++;
                        }
                    }

                    $tickets = $pageMetadata['tickets'];

                    if (empty($tickets)) {
                        break; // No more tickets
                    }

                    foreach ($tickets as $ticket) {
                        $counters['tickets_seen']++;

                        try {
                            $status = $cacheService->upsertOrRefreshFromSearchResult($ticket);
                            if (array_key_exists($status, $counters)) {
                                $counters[$status]++;
                            } else {
                                $counters[$status] = 1;
                            }
                        } catch (Throwable $e) {
                            $this->error('Error caching ticket: '.$e->getMessage());
                            $counters['errors']++;
                        }
                    }

                    $offset += count($tickets);
                }
            }
        } catch (Throwable $e) {
            $this->error('Error warming cache: '.$e->getMessage());
            $counters['errors']++;
        }

        $this->info('Cache warming complete.');
        $this->table(
            ['Metric', 'Count'],
            collect($counters)->map(fn ($value, $key) => [$key, $value])->toArray()
        );

        if ($shouldAudit) {
            $actionName = $counters['errors'] > 0 ? 'znuny.ticket_workspace_sync.failed' : 'znuny.ticket_workspace_sync.completed';
            AuditLogger::log(
                $actionName,
                'system',
                null,
                [
                    'source' => $isManual ? 'manual' : 'scheduled',
                    'manual' => $isManual,
                    'scheduled' => ! $isManual,
                    'state_types' => $combinedStateTypes,
                    'limit' => $limit,
                    'max_pages' => $maxPages,
                    'total_count' => $counters['total_count'],
                    'stats' => [
                        'cached_new' => $counters['cached_new'] ?? 0,
                        'refreshed_unchanged' => $counters['refreshed_unchanged'] ?? 0,
                        'updated_changed' => $counters['updated_changed'] ?? 0,
                        'skipped_disabled' => $counters['skipped_disabled'] ?? 0,
                        'errors' => $counters['errors'] ?? 0,
                    ],
                    'warnings' => $counters['warnings'] ?? 0,
                ]
            );
        }

        if ($this->option('scheduled')) {
            Redis::set('znuny:ticket_workspace:last_warm_at', Carbon::now()->timestamp);
        }

        return self::SUCCESS;
    }
}
