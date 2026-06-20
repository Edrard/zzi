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

    private function isDuplicateEventIdException(QueryException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;

        if ($driverCode !== 1062) {
            return false;
        }

        return str_contains($e->getMessage(), 'zabbix_event_id');
    }
}
