<?php

namespace App\Console\Commands;

use App\Services\Znuny\ClosedTicketSyncService;
use Illuminate\Console\Command;

class SyncClosedTicketCacheCommand extends Command
{
    protected $signature = 'znuny:sync-closed-ticket-cache {--manual} {--full}';

    protected $description = 'Sync recent closed tickets from Znuny to Redis cache';

    public function handle(ClosedTicketSyncService $syncService): int
    {
        $isManual = $this->option('manual');
        $isFull = $this->option('full');

        if ($isManual && $isFull) {
            $this->error('Cannot use --manual and --full together.');

            return self::FAILURE;
        }

        if ($isFull) {
            $this->info('Starting forced full closed ticket sync...');
            $result = $syncService->syncFull('full', 'forced_full');
        } elseif ($isManual) {
            $this->info('Starting manual small closed ticket sync...');
            $result = $syncService->syncManual();
        } else {
            $this->info('Starting auto closed ticket sync...');
            $result = $syncService->syncAuto();
        }

        if (($result['effective_mode'] ?? '') === 'skipped') {
            $this->warn("Sync skipped: {$result['reason']}");

            return self::SUCCESS;
        }

        $this->info("Completed {$result['effective_mode']} sync.");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Mode', $result['mode']],
                ['Effective Mode', $result['effective_mode']],
                ['Reason', $result['reason']],
                ['Fetched Count', $result['fetched_count']],
                ['Cached Count', $result['cached_count']],
                ['Duration (ms)', $result['duration_ms']],
                ['Metadata Status', $result['metadata_status']],
            ]
        );

        if (! empty($result['error_message'])) {
            $this->error('Completed with errors: '.$result['error_message']);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
