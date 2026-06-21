<?php

namespace App\Services\Znuny;

use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ZnunyTicketCreationService
{
    private const DEFAULT_PRIORITY = '3 normal';

    public function __construct(
        protected ZnunyClient $client,
        protected ZabbixTicketLinkService $linkService
    ) {}

    private function auditLog(
        string $action,
        string $eventId,
        string $hostName,
        string $problemName,
        string $queue,
        string|int $ownerId,
        string $customerUser,
        ?int $ticketId = null,
        ?string $ticketNumber = null,
        array $errors = [],
        array $warnings = [],
        bool $duplicate = false,
        bool $locked = false,
        bool $orphaned = false
    ): void {
        try {
            AuditLogger::log(
                $action,
                'zabbix_problem',
                $eventId,
                [
                    'zabbix_event_id' => $eventId,
                    'zabbix_host_name' => $hostName,
                    'zabbix_problem_name' => $problemName,
                    'znuny_queue_name' => $queue,
                    'znuny_owner_id' => $ownerId,
                    'customer_user' => $customerUser,
                    'ticket_id' => $ticketId,
                    'ticket_number' => $ticketNumber,
                    'errors' => $errors,
                    'warnings' => $warnings,
                    'duplicate' => $duplicate,
                    'locked' => $locked,
                    'orphaned' => $orphaned,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Failed to write audit log for manual ticket creation: '.$e->getMessage());
        }
    }

    public function buildValidationPayload(
        int|string $ownerId,
        string $queue,
        string $customerUser
    ): array {
        return [
            'OwnerID' => (int) $ownerId,
            'Queue' => $queue,
            'CustomerUser' => $customerUser,
            'State' => 'new',
            'Lock' => 'lock',
            'Priority' => self::DEFAULT_PRIORITY,
        ];
    }

    public function buildCreatePayload(
        int|string $ownerId,
        string $queue,
        string $customerUser,
        string $title,
        string $articleSubject,
        string $articleBody
    ): array {
        return [
            'Ticket' => [
                'Title' => $title,
                'Queue' => $queue,
                'CustomerUser' => $customerUser,
                'State' => 'new',
                'Lock' => 'lock',
                'OwnerID' => (int) $ownerId,
                'Priority' => self::DEFAULT_PRIORITY,
            ],
            'Article' => [
                'Subject' => $articleSubject,
                'Body' => $articleBody,
                'ContentType' => 'text/plain; charset=utf8',
            ],
        ];
    }

    /**
     * @return array{
     *   valid: bool,
     *   errors: array<int, string>,
     *   warnings: array<int, string>
     * }
     */
    public function validateTicketPayload(
        int|string $ownerId,
        string $queue,
        string $customerUser,
        string $title,
        string $articleSubject,
        string $articleBody
    ): array {
        if (empty(trim((string) $ownerId)) || empty(trim($queue)) || empty(trim($customerUser))) {
            return [
                'valid' => false,
                'errors' => ['Missing required Owner, Queue, or CustomerUser.'],
                'warnings' => [],
            ];
        }

        if (empty(trim($title))) {
            return [
                'valid' => false,
                'errors' => ['Ticket title is required.'],
                'warnings' => [],
            ];
        }

        if (empty(trim($articleBody))) {
            return [
                'valid' => false,
                'errors' => ['Ticket article body is required.'],
                'warnings' => [],
            ];
        }

        if (empty(trim($articleSubject))) {
            return [
                'valid' => false,
                'errors' => ['Ticket article subject is required.'],
                'warnings' => [],
            ];
        }

        $payload = $this->buildValidationPayload($ownerId, $queue, $customerUser);

        try {
            $response = $this->client->validateTicketCreate($payload);

            return [
                'valid' => ! empty($response['valid']),
                'errors' => $response['errors'] ?? [],
                'warnings' => $response['warnings'] ?? [],
            ];
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'errors' => [$e->getMessage()],
                'warnings' => [],
            ];
        }
    }

    public function createTicketForProblem(
        string $eventId,
        string $hostName,
        string $problemName,
        string|int $ownerId,
        string $queue,
        string $customerUser,
        string $title,
        string $articleSubject,
        string $articleBody,
        ?string $hostId = null,
        ?string $triggerId = null,
        ?string $startedAt = null
    ): array {
        $result = [
            'success' => false,
            'ticket_id' => null,
            'ticket_number' => null,
            'errors' => [],
            'warnings' => [],
            'duplicate' => false,
            'locked' => false,
            'orphaned' => false,
        ];

        $missing = [];
        if (empty(trim($eventId))) {
            $missing[] = 'event ID';
        }
        if (empty(trim($hostName))) {
            $missing[] = 'host name';
        }
        if (empty(trim($problemName))) {
            $missing[] = 'problem name';
        }
        if (empty(trim((string) $ownerId))) {
            $missing[] = 'owner';
        }
        if (empty(trim($queue))) {
            $missing[] = 'queue';
        }
        if (empty(trim($customerUser))) {
            $missing[] = 'customer user';
        }
        if (empty(trim($title))) {
            $missing[] = 'title';
        }
        if (empty(trim($articleSubject))) {
            $missing[] = 'article subject';
        }
        if (empty(trim($articleBody))) {
            $missing[] = 'article body';
        }

        if (! empty($missing)) {
            $result['errors'][] = 'Missing required fields for ticket creation: '.implode(', ', $missing).'.';
            $this->auditLog('znuny.manual_ticket_create.failed', $eventId, $hostName, $problemName, $queue, $ownerId, $customerUser, null, null, $result['errors'], [], false, false, false);

            return $result;
        }

        $this->auditLog('znuny.manual_ticket_create.attempt', $eventId, $hostName, $problemName, $queue, $ownerId, $customerUser);

        $lockKey = "zbx_ticket_create:{$eventId}";
        $lock = Cache::lock($lockKey, 10);

        if (! $lock->get()) {
            $result['locked'] = true;
            $result['errors'][] = 'Ticket creation is already in progress for this Zabbix event.';

            $this->auditLog('znuny.manual_ticket_create.locked', $eventId, $hostName, $problemName, $queue, $ownerId, $customerUser, null, null, $result['errors'], [], false, true, false);

            return $result;
        }

        try {
            if ($this->linkService->existsForEventId($eventId)) {
                $result['duplicate'] = true;
                $existing = $this->linkService->findByEventId($eventId);
                if ($existing) {
                    $result['ticket_id'] = $existing->znuny_ticket_id;
                    $result['ticket_number'] = $existing->znuny_ticket_number;
                }
                $result['errors'][] = 'A ticket is already linked to this Zabbix event.';

                $this->auditLog('znuny.manual_ticket_create.duplicate', $eventId, $hostName, $problemName, $queue, $ownerId, $customerUser, $result['ticket_id'] ?? null, $result['ticket_number'] ?? null, $result['errors'], [], true, false, false);

                return $result;
            }

            $validation = $this->validateTicketPayload($ownerId, $queue, $customerUser, $title, $articleSubject, $articleBody);

            if (! $validation['valid']) {
                $result['errors'] = $validation['errors'];
                $result['warnings'] = $validation['warnings'];

                $this->auditLog('znuny.manual_ticket_create.failed', $eventId, $hostName, $problemName, $queue, $ownerId, $customerUser, null, null, $result['errors'], $result['warnings'], false, false, false);

                return $result;
            }

            $payload = $this->buildCreatePayload($ownerId, $queue, $customerUser, $title, $articleSubject, $articleBody);

            try {
                $createResponse = $this->client->createTicket($payload);
            } catch (\Throwable $e) {
                $result['errors'][] = $e->getMessage();

                $this->auditLog('znuny.manual_ticket_create.failed', $eventId, $hostName, $problemName, $queue, $ownerId, $customerUser, null, null, $result['errors'], [], false, false, false);

                return $result;
            }

            if (! $createResponse['success']) {
                $result['errors'] = $createResponse['errors'];
                $result['warnings'] = $createResponse['warnings'] ?? [];

                $this->auditLog('znuny.manual_ticket_create.failed', $eventId, $hostName, $problemName, $queue, $ownerId, $customerUser, null, null, $result['errors'], $result['warnings'], false, false, false);

                return $result;
            }

            $ticketId = $createResponse['ticket_id'];
            $ticketNumber = $createResponse['ticket_number'];

            if (empty($ticketId) || empty($ticketNumber)) {
                $result['errors'][] = 'Ticket created but missing TicketID or TicketNumber in response.';

                $this->auditLog('znuny.manual_ticket_create.failed', $eventId, $hostName, $problemName, $queue, $ownerId, $customerUser, null, null, $result['errors'], [], false, false, false);

                return $result;
            }

            try {
                $this->linkService->create([
                    'zabbix_event_id' => $eventId,
                    'zabbix_host_id' => $hostId,
                    'zabbix_trigger_id' => $triggerId,
                    'zabbix_host_name' => $hostName,
                    'zabbix_problem_name' => $problemName,
                    'zabbix_started_at' => $startedAt,
                    'creation_source' => 'manual',
                    'znuny_ticket_id' => $ticketId,
                    'znuny_ticket_number' => $ticketNumber,
                    'znuny_queue_name' => $queue,
                    'znuny_owner_id' => $ownerId,
                    'created_by' => auth()->id() ?? null,
                ]);
            } catch (\Throwable $e) {
                Log::critical('Ticket created in Znuny but local DB write failed', [
                    'zabbix_event_id' => $eventId,
                    'ticket_id' => $ticketId,
                    'ticket_number' => $ticketNumber,
                    'exception' => $e->getMessage(),
                ]);
                $result['orphaned'] = true;
                $result['ticket_id'] = $ticketId;
                $result['ticket_number'] = $ticketNumber;
                $result['errors'][] = 'Znuny ticket was created but linking to Zabbix problem failed locally.';

                $this->auditLog('znuny.manual_ticket_create.orphaned', $eventId, $hostName, $problemName, $queue, $ownerId, $customerUser, $ticketId, $ticketNumber, $result['errors'], [], false, false, true);

                return $result;
            }

            $result['success'] = true;
            $result['ticket_id'] = $ticketId;
            $result['ticket_number'] = $ticketNumber;
            $result['warnings'] = $createResponse['warnings'] ?? [];

            $this->auditLog('znuny.manual_ticket_create.created', $eventId, $hostName, $problemName, $queue, $ownerId, $customerUser, $ticketId, $ticketNumber, [], $result['warnings'], false, false, false);

            return $result;

        } finally {
            $lock->release();
        }
    }
}
