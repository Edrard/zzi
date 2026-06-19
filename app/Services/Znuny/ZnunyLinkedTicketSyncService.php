<?php

namespace App\Services\Znuny;

use App\Models\AuditLog;
use App\Models\ZabbixTicket;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Throwable;

class ZnunyLinkedTicketSyncService
{
    public function __construct(
        private ZnunyClient $znunyClient,
        private ZnunyTicketSnapshotNormalizer $normalizer
    ) {}

    public function sync(int $batchSize = 0, ?int $ticketId = null): array
    {
        $query = ZabbixTicket::whereNotNull('znuny_ticket_id');

        if ($ticketId) {
            $query->where('znuny_ticket_id', $ticketId);
        }

        if ($batchSize > 0) {
            $query->limit($batchSize);
        }

        // Ideally process tickets that are not closed locally, or oldest checked first
        $query->orderBy('znuny_ticket_last_checked_at', 'asc');

        $tickets = $query->get();

        $stats = [
            'scanned' => 0,
            'synced' => 0,
            'unchanged' => 0,
            'missing' => 0,
            'failed' => 0,
        ];

        $detailedAudit = SettingsService::bool('znuny_detailed_sync_audit_enabled', false);

        foreach ($tickets as $localTicket) {
            $stats['scanned']++;
            $now = Carbon::now();

            $localTicket->znuny_ticket_last_checked_at = $now;

            try {
                $znunyTicket = $this->znunyClient->getTicket($localTicket->znuny_ticket_id);
                $normalized = $this->normalizer->normalize($znunyTicket);
                $hash = $this->normalizer->hash($normalized);

                if ($localTicket->znuny_ticket_snapshot_hash !== $hash) {
                    $oldState = $localTicket->znuny_state_name;

                    $localTicket->fill($normalized);
                    $localTicket->znuny_ticket_snapshot_hash = $hash;
                    $localTicket->znuny_ticket_last_synced_at = $now;
                    $localTicket->znuny_ticket_sync_error = null;
                    $localTicket->save();

                    $stats['synced']++;

                    if ($detailedAudit) {
                        AuditLog::create([
                            'action' => 'znuny_ticket_sync_updated',
                            'entity_type' => ZabbixTicket::class,
                            'entity_id' => $localTicket->id,
                            'user_id' => null,
                            'context' => [
                                'description' => "Linked ticket {$localTicket->znuny_ticket_number} (ID: {$localTicket->znuny_ticket_id}) snapshot updated.",
                                'ticket_id' => $localTicket->znuny_ticket_id,
                                'old_state' => $oldState,
                                'new_state' => $normalized['znuny_state_name'],
                                'hash' => $hash,
                            ],
                        ]);
                    }
                } else {
                    $localTicket->znuny_ticket_last_synced_at = $now;
                    $localTicket->znuny_ticket_sync_error = null;
                    $localTicket->save();

                    $stats['unchanged']++;
                }
            } catch (Throwable $e) {
                if (str_contains($e->getMessage(), 'Ticket not found in Znuny.')) {
                    $localTicket->znuny_ticket_sync_error = 'Ticket not found in Znuny.';
                    $stats['missing']++;

                    if ($detailedAudit) {
                        AuditLog::create([
                            'action' => 'znuny_ticket_sync_missing',
                            'entity_type' => ZabbixTicket::class,
                            'entity_id' => $localTicket->id,
                            'user_id' => null,
                            'context' => [
                                'description' => "Linked ticket ID {$localTicket->znuny_ticket_id} not found in Znuny.",
                                'ticket_id' => $localTicket->znuny_ticket_id,
                                'error' => $e->getMessage(),
                            ],
                        ]);
                    }
                } else {
                    $localTicket->znuny_ticket_sync_error = 'API Error: '.$e->getMessage();
                    $stats['failed']++;

                    if ($detailedAudit) {
                        AuditLog::create([
                            'action' => 'znuny_ticket_sync_failed',
                            'entity_type' => ZabbixTicket::class,
                            'entity_id' => $localTicket->id,
                            'user_id' => null,
                            'context' => [
                                'description' => "Failed to sync linked ticket ID {$localTicket->znuny_ticket_id}.",
                                'ticket_id' => $localTicket->znuny_ticket_id,
                                'error' => $e->getMessage(),
                            ],
                        ]);
                    }
                }

                $localTicket->save();
            }
        }

        return $stats;
    }
}
