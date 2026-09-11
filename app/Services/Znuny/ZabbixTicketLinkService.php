<?php

namespace App\Services\Znuny;

use App\Exceptions\ZabbixTicketAlreadyLinkedException;
use App\Models\ZabbixTicket;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ZabbixTicketLinkService
{
    /**
     * Find a local ticket relation by Zabbix Event ID.
     */
    public function findByEventId(string|int $eventId): ?ZabbixTicket
    {
        $normalizedId = (string) $eventId;
        if ($normalizedId === '') {
            return null;
        }

        return ZabbixTicket::where('zabbix_event_id', $normalizedId)->first();
    }

    /**
     * Check if a local ticket relation exists for a Zabbix Event ID.
     */
    public function existsForEventId(string|int $eventId): bool
    {
        $normalizedId = (string) $eventId;
        if ($normalizedId === '') {
            return false;
        }

        return ZabbixTicket::where('zabbix_event_id', $normalizedId)->exists();
    }

    /**
     * Create a new persistent Zabbix to Znuny Ticket relation.
     * Enforces required fields, ignores duplicate unique constraints securely, and logs auditing cleanly.
     *
     * @throws ZabbixTicketAlreadyLinkedException
     * @throws QueryException
     */
    public function create(array $data): ZabbixTicket
    {
        $requiredStringFields = [
            'zabbix_event_id',
            'zabbix_host_name',
            'zabbix_problem_name',
            'znuny_ticket_number',
        ];

        foreach ($requiredStringFields as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidArgumentException("Missing required field for relation creation: {$field}");
            }
            $data[$field] = trim((string) $data[$field]);
            if ($data[$field] === '') {
                throw new InvalidArgumentException("Required field cannot be empty: {$field}");
            }
        }

        if (! array_key_exists('znuny_ticket_id', $data) || $data['znuny_ticket_id'] === null || $data['znuny_ticket_id'] === '') {
            throw new InvalidArgumentException('Missing required field for relation creation: znuny_ticket_id');
        }

        if ($this->existsForEventId($data['zabbix_event_id'])) {
            throw new ZabbixTicketAlreadyLinkedException("A Znuny ticket is already linked to Zabbix Event ID {$data['zabbix_event_id']}.");
        }

        return DB::transaction(function () use ($data) {
            try {
                $ticket = ZabbixTicket::create([
                    'zabbix_event_id' => $data['zabbix_event_id'],
                    'zabbix_trigger_id' => isset($data['zabbix_trigger_id']) ? (string) $data['zabbix_trigger_id'] : null,
                    'zabbix_host_id' => isset($data['zabbix_host_id']) ? (string) $data['zabbix_host_id'] : null,
                    'zabbix_host_name' => $data['zabbix_host_name'],
                    'zabbix_problem_name' => $data['zabbix_problem_name'],
                    'zabbix_severity' => $data['zabbix_severity'] ?? null,
                    'zabbix_started_at' => $data['zabbix_started_at'] ?? null,
                    'creation_source' => $data['creation_source'] ?? 'manual',
                    'znuny_ticket_id' => $data['znuny_ticket_id'],
                    'znuny_ticket_number' => $data['znuny_ticket_number'],
                    'znuny_queue_id' => $data['znuny_queue_id'] ?? null,
                    'znuny_queue_name' => $data['znuny_queue_name'] ?? null,
                    'znuny_owner_id' => $data['znuny_owner_id'] ?? null,
                    'znuny_owner_name' => $data['znuny_owner_name'] ?? null,
                    'znuny_state_id' => $data['znuny_state_id'] ?? null,
                    'znuny_state_name' => $data['znuny_state_name'] ?? null,
                    'created_by' => $data['created_by'] ?? null,
                ]);
            } catch (QueryException $e) {
                if ($this->isDuplicateEventIdException($e)) {
                    throw new ZabbixTicketAlreadyLinkedException("A Znuny ticket is already linked to Zabbix Event ID {$data['zabbix_event_id']}.");
                }

                throw $e;
            }

            AuditLogger::log(
                action: 'zabbix_ticket.link_created',
                entityType: 'zabbix_ticket',
                entityId: $ticket->id,
                context: [
                    'zabbix_event_id' => $ticket->zabbix_event_id,
                    'zabbix_trigger_id' => $ticket->zabbix_trigger_id,
                    'zabbix_host_name' => $ticket->zabbix_host_name,
                    'znuny_ticket_id' => $ticket->znuny_ticket_id,
                    'znuny_ticket_number' => $ticket->znuny_ticket_number,
                    'znuny_queue_id' => $ticket->znuny_queue_id,
                    'znuny_queue_name' => $ticket->znuny_queue_name,
                    'znuny_owner_id' => $ticket->znuny_owner_id,
                    'znuny_owner_name' => $ticket->znuny_owner_name,
                    'created_by' => $ticket->created_by,
                ]
            );

            return $ticket;
        });
    }

    /**
     * Replace an existing terminal ticket link with a newly created Znuny ticket for the same Zabbix event.
     *
     * @throws \LogicException
     * @throws InvalidArgumentException
     */
    public function replaceTerminalTicketLink(ZabbixTicket $existingTicket, array $data): ZabbixTicket
    {
        if (isset($data['zabbix_event_id']) && (string) $data['zabbix_event_id'] !== (string) $existingTicket->zabbix_event_id) {
            throw new InvalidArgumentException('Cannot replace link for mismatched Zabbix Event ID.');
        }

        if (! array_key_exists('znuny_ticket_id', $data) || $data['znuny_ticket_id'] === null || $data['znuny_ticket_id'] === '') {
            throw new InvalidArgumentException('Missing required field for relation replacement: znuny_ticket_id');
        }

        if (! array_key_exists('znuny_ticket_number', $data) || trim((string) $data['znuny_ticket_number']) === '') {
            throw new InvalidArgumentException('Missing required field for relation replacement: znuny_ticket_number');
        }

        return DB::transaction(function () use ($existingTicket, $data) {
            $ticket = ZabbixTicket::where('id', $existingTicket->id)->lockForUpdate()->firstOrFail();

            if (! $ticket->isTerminalInZnuny()) {
                throw new \LogicException("Cannot replace link for Zabbix Event ID {$ticket->zabbix_event_id} because existing ticket {$ticket->znuny_ticket_id} is not in a terminal state.");
            }

            $oldTicketId = $ticket->znuny_ticket_id;
            $oldTicketNumber = $ticket->znuny_ticket_number;
            $oldStateType = $ticket->znuny_ticket_state_type;
            $oldStateName = $ticket->znuny_state_name;

            $ticket->znuny_ticket_id = $data['znuny_ticket_id'];
            $ticket->znuny_ticket_number = trim((string) $data['znuny_ticket_number']);
            $ticket->znuny_queue_id = $data['znuny_queue_id'] ?? null;
            $ticket->znuny_queue_name = $data['znuny_queue_name'] ?? null;
            $ticket->znuny_owner_id = $data['znuny_owner_id'] ?? null;
            $ticket->znuny_owner_name = $data['znuny_owner_name'] ?? null;
            $ticket->znuny_state_id = $data['znuny_state_id'] ?? null;
            $ticket->znuny_state_name = $data['znuny_state_name'] ?? null;
            $ticket->znuny_ticket_state_type = $data['znuny_ticket_state_type'] ?? null;
            $ticket->creation_source = $data['creation_source'] ?? 'manual';
            $ticket->created_by = $data['created_by'] ?? null;

            if (array_key_exists('zabbix_trigger_id', $data)) {
                $ticket->zabbix_trigger_id = $data['zabbix_trigger_id'] !== null ? (string) $data['zabbix_trigger_id'] : null;
            }
            if (array_key_exists('zabbix_host_id', $data)) {
                $ticket->zabbix_host_id = $data['zabbix_host_id'] !== null ? (string) $data['zabbix_host_id'] : null;
            }
            if (! empty($data['zabbix_host_name'])) {
                $ticket->zabbix_host_name = (string) $data['zabbix_host_name'];
            }
            if (! empty($data['zabbix_problem_name'])) {
                $ticket->zabbix_problem_name = (string) $data['zabbix_problem_name'];
            }
            if (array_key_exists('zabbix_severity', $data)) {
                $ticket->zabbix_severity = $data['zabbix_severity'];
            }
            if (array_key_exists('zabbix_started_at', $data)) {
                $ticket->zabbix_started_at = $data['zabbix_started_at'];
            }

            // Reset stale terminal and lifecycle fields
            $ticket->znuny_ticket_changed_at = null;
            $ticket->znuny_ticket_last_checked_at = null;
            $ticket->znuny_ticket_last_synced_at = null;
            $ticket->znuny_ticket_closed_at = null;
            $ticket->znuny_ticket_sync_error = null;
            $ticket->znuny_ticket_snapshot_hash = null;
            $ticket->manual_lifecycle_status = null;
            $ticket->manual_lifecycle_closed_at = null;
            $ticket->manual_close_eligible_at = null;
            $ticket->manual_reopened_at = null;
            $ticket->manual_lifecycle_last_checked_at = null;
            $ticket->zabbix_problem_resolved_at = null;
            $ticket->manual_flap_count = 0;
            $ticket->manual_flapping_detected_at = null;
            $ticket->zabbix_last_counted_flap_event_id = null;
            $ticket->zabbix_last_counted_flap_started_at = null;
            $ticket->manual_last_flap_counted_at = null;

            $ticket->save();

            AuditLogger::log(
                action: 'zabbix_ticket.link_replaced',
                entityType: 'zabbix_ticket',
                entityId: $ticket->id,
                context: [
                    'zabbix_event_id' => $ticket->zabbix_event_id,
                    'old_ticket_id' => $oldTicketId,
                    'old_ticket_number' => $oldTicketNumber,
                    'old_state_type' => $oldStateType,
                    'old_state_name' => $oldStateName,
                    'new_ticket_id' => $ticket->znuny_ticket_id,
                    'new_ticket_number' => $ticket->znuny_ticket_number,
                    'zabbix_trigger_id' => $ticket->zabbix_trigger_id,
                    'zabbix_host_name' => $ticket->zabbix_host_name,
                    'znuny_queue_id' => $ticket->znuny_queue_id,
                    'znuny_queue_name' => $ticket->znuny_queue_name,
                    'znuny_owner_id' => $ticket->znuny_owner_id,
                    'znuny_owner_name' => $ticket->znuny_owner_name,
                    'created_by' => $ticket->created_by,
                ]
            );

            return $ticket;
        });
    }

    private function isDuplicateEventIdException(QueryException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;

        if ($driverCode !== 1062) {
            return false;
        }

        return str_contains($e->getMessage(), 'zabbix_event_id');
    }
}
