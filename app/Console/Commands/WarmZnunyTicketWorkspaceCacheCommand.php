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

        $counters = [
            'state_types' => count($mappedStateTypes),
            'pages_requested' => 0,
            'tickets_seen' => 0,
            'cached_new' => 0,
            'refreshed_unchanged' => 0,
            'updated_changed' => 0,
            'skipped_missing_ticket_id' => 0,
            'errors' => 0,
        ];

        foreach ($mappedStateTypes as $stateType) {
            $this->info("Warming cache for StateType: {$stateType}");

            for ($page = 1; $page <= $maxPages; $page++) {
                try {
                    $offset = ($page - 1) * $limit;

                    $filters = [
                        'StateType' => $stateType,
                        'Limit' => $limit,
                        'Offset' => $offset,
                        'SortBy' => 'Changed',
                        'SortDirection' => 'Down',
                    ];

                    $counters['pages_requested']++;
                    $tickets = $client->searchTickets($filters);

                    if (empty($tickets)) {
                        break; // No more tickets for this state type
                    }

                    foreach ($tickets as $ticket) {
                        $counters['tickets_seen']++;

                        try {
                            $status = $cacheService->upsertOrRefreshFromSearchResult($ticket);
                            $counters[$status]++;
                        } catch (Throwable $e) {
                            $this->error('Error caching ticket: '.$e->getMessage());
                            $counters['errors']++;
                        }
                    }

                    if (count($tickets) < $limit) {
                        break; // Last page reached for this state type
                    }

                } catch (Throwable $e) {
                    $this->error("Error fetching tickets for StateType {$stateType} on page {$page}: ".$e->getMessage());
                    $counters['errors']++;
                    break; // Skip to next state type on api error
                }
            }
        }

        $this->info('Cache warming complete.');
        $this->table(
            ['Metric', 'Count'],
            collect($counters)->map(fn ($value, $key) => [$key, $value])->toArray()
        );

        return self::SUCCESS;
    }
}
