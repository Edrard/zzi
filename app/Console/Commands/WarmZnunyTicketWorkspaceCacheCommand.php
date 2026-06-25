<?php

namespace App\Console\Commands;

use App\Services\SettingsService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketCacheService;
use App\Services\Znuny\ZnunyTicketWorkspaceStateTypeMapper;
use Illuminate\Console\Command;
use Throwable;

class WarmZnunyTicketWorkspaceCacheCommand extends Command
{
    protected $signature = 'znuny:warm-ticket-workspace-cache';

    protected $description = 'Warm up the Ticket Workspace cache by polling active tickets from Znuny';

    public function handle(
        ZnunyClient $client,
        ZnunyTicketCacheService $cacheService,
        ZnunyTicketWorkspaceStateTypeMapper $mapper
    ): int {
        $enabled = SettingsService::bool('znuny_ticket_workspace_enabled', false) ?? false;

        if (! $enabled) {
            $this->info('Ticket Workspace is disabled in settings. Exiting cleanly.');

            return self::SUCCESS;
        }

        $activeStateTypeIdsJson = SettingsService::string('znuny_ticket_workspace_active_state_type_ids', '[]');
        $activeStateTypeIds = json_decode($activeStateTypeIdsJson, true) ?? [];

        if (empty($activeStateTypeIds) || ! is_array($activeStateTypeIds)) {
            $this->info('No active state type IDs configured. Exiting.');

            return self::SUCCESS;
        }

        $mappedStateTypes = $mapper->mapInternalIdsToZnunyTypes($activeStateTypeIds);

        if (empty($mappedStateTypes)) {
            $this->info('No matching Znuny StateTypes found for the configured IDs. Exiting.');

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

                for ($page = 1; $page <= $maxPages; $page++) {
                    $offset = ($page - 1) * $limit;

                    if ($offset >= $totalCount) {
                        break;
                    }

                    $filters = [
                        'StateType' => $combinedStateTypes,
                        'Limit' => $limit,
                        'Offset' => $offset,
                        'SortBy' => 'Changed',
                        'SortDirection' => 'DESC',
                    ];

                    $counters['pages_requested']++;
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

        return self::SUCCESS;
    }
}
