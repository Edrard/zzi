<?php

namespace App\Console\Commands;

use App\Services\SettingsService;
use App\Services\Znuny\ZnunyLinkedTicketSyncService;
use Illuminate\Console\Command;

class SyncLinkedZnunyTicketsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'znuny:sync-linked-tickets {--ticket-id= : Focused debugging for a specific TicketID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Read-only synchronization of linked Znuny tickets.';

    /**
     * Execute the console command.
     */
    public function handle(ZnunyLinkedTicketSyncService $syncService): int
    {
        $this->info('Starting Znuny linked tickets sync...');

        $ticketId = $this->option('ticket-id') ? (int) $this->option('ticket-id') : null;
        $batchSize = SettingsService::int('znuny_linked_ticket_sync_batch_size', 0);

        if ($batchSize < 0) {
            $batchSize = 0;
        }

        $stats = $syncService->sync($batchSize, $ticketId);

        $this->info('Sync completed.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Scanned', $stats['scanned']],
                ['Synced/Updated', $stats['synced']],
                ['Unchanged', $stats['unchanged']],
                ['Missing', $stats['missing']],
                ['Failed', $stats['failed']],
            ]
        );

        return self::SUCCESS;
    }
}
