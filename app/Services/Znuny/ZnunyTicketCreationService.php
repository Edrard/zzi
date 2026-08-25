<?php

namespace App\Services\Znuny;

use App\Enums\ZnunyTicketCreationClassification;
use App\Services\AuditLogger;
use App\Services\OwnerSuggestion\OwnerSuggestionObservationRecorder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ZnunyTicketCreationService
{
    private const DEFAULT_PRIORITY = '3 normal';

    public function __construct(
        protected ZnunyClient $client,
        protected ZabbixTicketLinkService $linkService,
        protected OwnerSuggestionObservationRecorder $observationRecorder
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
        string $customerUser,
        string $customerId
    ): array {
        $defaults = app(ZnunyTicketAdvancedDefaultsService::class)->getDefaults();

        return [
            'OwnerID' => (int) $ownerId,
            'Queue' => $queue,
            'CustomerUser' => $customerUser,
            'CustomerID' => $customerId,
            'State' => $defaults['state'],
            'Lock' => $defaults['lock'],
            'Priority' => $defaults['priority'],
        ];
    }

    public function buildCreatePayload(
        int|string $ownerId,
        string $queue,
        string $customerUser,
        string $customerId,
        string $title,
        string $articleSubject,
        string $articleBody
    ): array {
        $defaults = app(ZnunyTicketAdvancedDefaultsService::class)->getDefaults();

        return [
            'Ticket' => [
                'Title' => $title,
                'Queue' => $queue,
                'CustomerUser' => $customerUser,
                'CustomerID' => $customerId,
                'State' => $defaults['state'],
                'Lock' => $defaults['lock'],
                'OwnerID' => (int) $ownerId,
                'Priority' => $defaults['priority'],
            ],
            'Article' => [
                'Subject' => $articleSubject,
                'Body' => $articleBody,
                'ContentType' => 'text/plain; charset=utf8',
                'IsVisibleForCustomer' => 1,
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
        string $customerId,
        string $title,
        string $articleSubject,
        string $articleBody
    ): array {
        if (empty(trim((string) $ownerId)) || empty(trim($queue)) || empty(trim($customerUser)) || empty(trim($customerId))) {
            return [
                'valid' => false,
                'errors' => ['Missing required Owner, Queue, CustomerUser, or CustomerID.'],
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

        $payload = $this->buildValidationPayload($ownerId, $queue, $customerUser, $customerId);

        try {
            $response = $this->client->validateTicketCreate($payload);
            $reliability = app(ZnunyTicketCreationReliabilityService::class);

            return [
                'valid' => ! empty($response['valid']),
                'errors' => $reliability->normalizedSanitizedField($response, 'errors'),
                'warnings' => $reliability->normalizedSanitizedField($response, 'warnings'),
            ];
        } catch (\Throwable $e) {
            $reliability = app(ZnunyTicketCreationReliabilityService::class);
            $sanitizedMessage = $reliability->sanitizeExceptionMessage($e->getMessage());
            $boundedMessage = substr($sanitizedMessage, 0, 150);

            return [
                'valid' => false,
                'errors' => [
                    get_class($e).': '.$boundedMessage,
                ],
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
            'classification' => 'not_sent',
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

            $customerData = $this->client->getCustomerUser($customerUser);
            if (! $customerData['found']) {
                $result['errors'][] = 'Failed to resolve CustomerUser: '.$customerUser;
                $this->auditLog('znuny.manual_ticket_create.failed', $eventId, $hostName, $problemName, $queue, $ownerId, $customerUser, null, null, $result['errors'], [], false, false, false);

                return $result;
            }

            $customerId = $customerData['customer_id'] ?? '';
            if (empty(trim($customerId))) {
                $result['errors'][] = "CustomerUser '{$customerUser}' has no CustomerID/UserCustomerID assigned.";
                $this->auditLog('znuny.manual_ticket_create.failed', $eventId, $hostName, $problemName, $queue, $ownerId, $customerUser, null, null, $result['errors'], [], false, false, false);

                return $result;
            }

            $validation = $this->validateTicketPayload($ownerId, $queue, $customerUser, $customerId, $title, $articleSubject, $articleBody);

            if (! $validation['valid']) {
                $result['errors'] = $validation['errors'];
                $result['warnings'] = $validation['warnings'];

                $this->auditLog('znuny.manual_ticket_create.failed', $eventId, $hostName, $problemName, $queue, $ownerId, $customerUser, null, null, $result['errors'], $result['warnings'], false, false, false);

                return $result;
            }

            $payload = $this->buildCreatePayload($ownerId, $queue, $customerUser, $customerId, $title, $articleSubject, $articleBody);

            $reliability = app(ZnunyTicketCreationReliabilityService::class);
            $attempt = $reliability->applyMarkerAndCreateAttempt(
                'zabbix_problem',
                $eventId,
                $title, // use $title as original subject since it's the main ticket title
                $payload,
                auth()->id() ?? null
            );

            try {
                $reliability->recordApiStart($attempt);
                $createResponse = $this->client->createTicket($payload);
            } catch (\Throwable $e) {
                $reliability->recordApiException($attempt, $e);
                $result['classification'] = ZnunyTicketCreationClassification::Uncertain->value;
                $sanitizedMessage = $reliability->sanitizeExceptionMessage($e->getMessage());
                $result['errors'][] = get_class($e).': '.substr($sanitizedMessage, 0, 150);

                $this->auditLog('znuny.manual_ticket_create.failed', $eventId, $hostName, $problemName, $queue, $ownerId, $customerUser, null, null, $result['errors'], [], false, false, false);

                return $result;
            }

            $classification = $reliability->recordApiResult($attempt, $createResponse);
            $result['classification'] = $classification->value;

            if ($classification === ZnunyTicketCreationClassification::Success) {
                $result['success'] = true;
                $result['ticket_id'] = $createResponse['ticket_id'];
                $result['ticket_number'] = $createResponse['ticket_number'];
                $ticketId = $result['ticket_id'];
                $ticketNumber = $result['ticket_number'];
            } elseif ($classification === ZnunyTicketCreationClassification::ConfirmedFailed) {
                $result['errors'] = $reliability->normalizedSanitizedField($createResponse, 'errors');
                $result['warnings'] = $reliability->normalizedSanitizedField($createResponse, 'warnings');
                $this->auditLog('znuny.manual_ticket_create.failed', $eventId, $hostName, $problemName, $queue, $ownerId, $customerUser, null, null, $result['errors'], $result['warnings'], false, false, false);

                return $result;
            } else {
                $normalizedErrors = $reliability->normalizedSanitizedField($createResponse, 'errors');
                $result['errors'] = ! empty($normalizedErrors) ? $normalizedErrors : ['Ambiguous or incomplete response from Znuny API.'];
                $result['warnings'] = $reliability->normalizedSanitizedField($createResponse, 'warnings');
                $this->auditLog('znuny.manual_ticket_create.failed', $eventId, $hostName, $problemName, $queue, $ownerId, $customerUser, null, null, $result['errors'], $result['warnings'], false, false, false);

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
                $result['success'] = false;
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
            $result['warnings'] = $reliability->normalizedSanitizedField($createResponse, 'warnings');

            $this->auditLog('znuny.manual_ticket_create.created', $eventId, $hostName, $problemName, $queue, $ownerId, $customerUser, $ticketId, $ticketNumber, [], $result['warnings'], false, false, false);

            try {
                $this->observationRecorder->recordManualTicketCreated([
                    'problem_name' => $problemName,
                    'queue_name' => $queue,
                    'owner_id' => $ownerId,
                    'zabbix_event_id' => $eventId,
                    'zabbix_host_name' => $hostName,
                    'customer_user_login' => $customerUser,
                    'znuny_ticket_id' => $ticketId,
                    'created_by_user_id' => auth()->id() ?? null,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to record manual ticket creation observation from ZnunyTicketCreationService', [
                    'error' => $e->getMessage(),
                    'zabbix_event_id' => $eventId,
                    'znuny_ticket_id' => $ticketId,
                ]);
            }

            return $result;

        } finally {
            $lock->release();
        }
    }
}
