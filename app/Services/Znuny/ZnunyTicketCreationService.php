<?php

namespace App\Services\Znuny;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ZnunyTicketCreationService
{
    public function __construct(
        protected ZnunyClient $client,
        protected ZabbixTicketLinkService $linkService
    ) {}

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

        $payload = [
            'Ticket' => [
                'Title' => $title,
                'Queue' => $queue,
                'CustomerUser' => $customerUser,
                'State' => 'new',
                'Lock' => 'lock',
                'OwnerID' => (int) $ownerId,
            ],
            'Article' => [
                'Subject' => $articleSubject,
                'Body' => $articleBody,
                'ContentType' => 'text/plain; charset=utf8',
            ],
        ];

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
        string $articleBody
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

        if (empty(trim($eventId)) || empty(trim($hostName)) || empty(trim($problemName)) || empty(trim((string) $ownerId)) || empty(trim($queue)) || empty(trim($customerUser)) || empty(trim($title)) || empty(trim($articleBody))) {
            $result['errors'][] = 'Missing required fields for ticket creation.';

            return $result;
        }

        $lockKey = "zbx_ticket_create:{$eventId}";
        $lock = Cache::lock($lockKey, 10);

        if (! $lock->get()) {
            $result['locked'] = true;
            $result['errors'][] = 'Ticket creation is already in progress for this Zabbix event.';

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

                return $result;
            }

            $validation = $this->validateTicketPayload($ownerId, $queue, $customerUser, $title, $articleSubject, $articleBody);

            if (! $validation['valid']) {
                $result['errors'] = $validation['errors'];
                $result['warnings'] = $validation['warnings'];

                return $result;
            }

            $payload = [
                'Ticket' => [
                    'Title' => $title,
                    'Queue' => $queue,
                    'CustomerUser' => $customerUser,
                    'State' => 'new',
                    'Lock' => 'lock',
                    'OwnerID' => (int) $ownerId,
                ],
                'Article' => [
                    'Subject' => $articleSubject,
                    'Body' => $articleBody,
                    'ContentType' => 'text/plain; charset=utf8',
                ],
            ];

            try {
                $createResponse = $this->client->createTicket($payload);
            } catch (\Throwable $e) {
                $result['errors'][] = $e->getMessage();

                return $result;
            }

            if (! $createResponse['success']) {
                $result['errors'] = $createResponse['errors'];
                $result['warnings'] = $createResponse['warnings'] ?? [];
                return $result;
            }

            $ticketId = $createResponse['ticket_id'];
            $ticketNumber = $createResponse['ticket_number'];

            if (empty($ticketId) || empty($ticketNumber)) {
                $result['errors'][] = 'Ticket created but missing TicketID or TicketNumber in response.';

                return $result;
            }

            try {
                $this->linkService->create([
                    'zabbix_event_id' => $eventId,
                    'zabbix_host_name' => $hostName,
                    'zabbix_problem_name' => $problemName,
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

                return $result;
            }

            $result['success'] = true;
            $result['ticket_id'] = $ticketId;
            $result['ticket_number'] = $ticketNumber;
            $result['warnings'] = $createResponse['warnings'] ?? [];

            return $result;

        } finally {
            $lock->release();
        }
    }
}
