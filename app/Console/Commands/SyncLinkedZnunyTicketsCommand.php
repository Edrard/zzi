<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
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
    protected $signature = 'znuny:sync-linked-tickets {--ticket-id= : Focused debugging for a specific TicketID} {--manual : Indicates the sync was triggered manually by an operator}';

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

        $isManual = (bool) $this->option('manual');
        $shouldAudit = $isManual || SettingsService::bool('znuny_detailed_sync_audit_enabled', false);

        try {
            $stats = $syncService->sync($batchSize, $ticketId);
        } catch (\Throwable $e) {
            $this->error('Sync failed: '.$e->getMessage());

            if ($shouldAudit) {
                AuditLogger::log(
                    'znuny.linked_tickets_sync.failed',
                    'system',
                    null,
                    [
                        'source' => $isManual ? 'manual' : 'scheduled',
                        'manual' => $isManual,
                        'scheduled' => ! $isManual,
                        'batch_size' => $batchSize,
                        'ticket_id' => $ticketId,
                        'error' => $e->getMessage(),
                    ]
                );
            }

            return self::FAILURE;
        }

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

        if ($shouldAudit) {
            $action = $stats['failed'] > 0 ? 'znuny.linked_tickets_sync.failed' : 'znuny.linked_tickets_sync.completed';

            AuditLogger::log(
                $action,
                'system',
                null,
                [
                    'source' => $isManual ? 'manual' : 'scheduled',
                    'manual' => $isManual,
                    'scheduled' => ! $isManual,
                    'batch_size' => $batchSize,
                    'ticket_id' => $ticketId,
                    'stats' => $stats,
                ]
            );
        }

        return self::SUCCESS;
    }
}
