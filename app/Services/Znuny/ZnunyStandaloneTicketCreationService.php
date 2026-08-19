<?php

namespace App\Services\Znuny;

use App\Services\AuditLogger;
use Exception;
use Illuminate\Support\Facades\Log;

class ZnunyStandaloneTicketCreationService
{
    protected ZnunyClient $client;

    public function __construct(ZnunyClient $client)
    {
        $this->client = $client;
    }

    private function auditLog(
        string $action,
        string $queue,
        string|int $ownerId,
        string $customerUser,
        ?int $ticketId = null,
        ?string $ticketNumber = null,
        array $errors = [],
        array $warnings = []
    ): void {
        try {
            AuditLogger::log(
                $action,
                'znuny_standalone_ticket',
                (string) $ticketId,
                [
                    'znuny_queue_name' => $queue,
                    'znuny_owner_id' => $ownerId,
                    'customer_user' => $customerUser,
                    'ticket_id' => $ticketId,
                    'ticket_number' => $ticketNumber,
                    'errors' => $errors,
                    'warnings' => $warnings,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Failed to write audit log for standalone ticket creation: '.$e->getMessage());
        }
    }

    public function createTicket(
        int|string $ownerId,
        string $queue,
        string $customerUser,
        string $title,
        string $articleBody,
        ?string $state = null,
        ?string $priority = null,
        ?string $lock = null,
        array $attachments = [],
        ?string $articleContentType = null
    ): array {
        $result = [
            'success' => false,
            'ticket_id' => null,
            'ticket_number' => null,
            'errors' => [],
            'warnings' => [],
        ];

        try {
            $customerData = $this->client->getCustomerUser($customerUser);

            if (! $customerData['found']) {
                $result['errors'][] = 'Failed to resolve CustomerUser: '.$customerUser;
                $this->auditLog('znuny.standalone_ticket.failed', $queue, $ownerId, $customerUser, null, null, $result['errors']);

                return $result;
            }

            $customerId = $customerData['customer_id'] ?? $customerData['user_customer_id'] ?? null;
            if (empty($customerId)) {
                $result['errors'][] = "CustomerUser '{$customerUser}' has no CustomerID/UserCustomerID assigned.";
                $this->auditLog('znuny.standalone_ticket.failed', $queue, $ownerId, $customerUser, null, null, $result['errors']);

                return $result;
            }

            if (empty(trim((string) $ownerId)) || empty(trim($queue)) || empty(trim($customerUser))) {
                $result['errors'][] = 'Missing required Owner, Queue, or CustomerUser.';
                $this->auditLog('znuny.standalone_ticket.failed', $queue, $ownerId, $customerUser, null, null, $result['errors']);

                return $result;
            }

            if (empty(trim($title)) || empty(trim($articleBody))) {
                $result['errors'][] = 'Ticket title and article body are required.';
                $this->auditLog('znuny.standalone_ticket.failed', $queue, $ownerId, $customerUser, null, null, $result['errors']);

                return $result;
            }

            $defaults = app(ZnunyTicketAdvancedDefaultsService::class)->getDefaults();

            if (empty(trim($state))) {
                $state = $defaults['state'];
            }
            if (empty(trim($priority))) {
                $priority = $defaults['priority'];
            }
            if (empty($lock) || ! in_array($lock, ['lock', 'unlock'])) {
                $lock = $defaults['lock'];
            }

            if (empty(trim($state)) || empty(trim($priority))) {
                $result['errors'][] = 'State and Priority are required by Znuny API.';
                $this->auditLog('znuny.standalone_ticket.failed', $queue, $ownerId, $customerUser, null, null, $result['errors']);

                return $result;
            }

            $validationPayload = [
                'OwnerID' => (int) $ownerId,
                'Queue' => $queue,
                'CustomerUser' => $customerUser,
                'CustomerID' => $customerId,
                'State' => $state,
                'Lock' => $lock,
                'Priority' => $priority,
            ];

            // Revalidate via preflight
            $validation = $this->client->validateTicketCreate($validationPayload);
            if (! $validation['valid']) {
                $result['errors'] = $validation['errors'];
                $result['warnings'] = $validation['warnings'] ?? [];
                $this->auditLog('znuny.standalone_ticket.failed_validation', $queue, $ownerId, $customerUser, null, null, $result['errors'], $result['warnings']);

                return $result;
            }

            $ticket = [
                'Title' => $title,
                'Queue' => $queue,
                'CustomerUser' => $customerUser,
                'CustomerID' => $customerId,
                'State' => $state,
                'Lock' => $lock,
                'OwnerID' => (int) $ownerId,
                'Priority' => $priority,
            ];

            $contentType = $articleContentType ?? 'text/plain; charset=utf8';

            $payload = [
                'Ticket' => $ticket,
                'Article' => [
                    'Subject' => $title,
                    'Body' => $articleBody,
                    'ContentType' => $contentType,
                    'IsVisibleForCustomer' => 1,
                ],
            ];

            if (! empty($attachments)) {
                $payload['Attachment'] = $attachments;
            }

            $apiResult = $this->client->createTicket($payload);

            if (! empty($apiResult['success']) && ! empty($apiResult['ticket_id']) && ! empty($apiResult['ticket_number'])) {
                $result['success'] = true;
                $result['ticket_id'] = $apiResult['ticket_id'];
                $result['ticket_number'] = $apiResult['ticket_number'];
                $result['warnings'] = $apiResult['warnings'] ?? [];

                $this->auditLog('znuny.standalone_ticket.created', $queue, $ownerId, $customerUser, $result['ticket_id'], $result['ticket_number'], [], $result['warnings']);
            } else {
                $result['errors'] = $apiResult['errors'] ?? ['Znuny API returned success but missing TicketID/TicketNumber.'];
                $result['warnings'] = $apiResult['warnings'] ?? [];
                $this->auditLog('znuny.standalone_ticket.failed', $queue, $ownerId, $customerUser, null, null, $result['errors'], $result['warnings']);
            }

        } catch (Exception $e) {
            $result['errors'][] = 'Failed to create ticket: '.$e->getMessage();
            $this->auditLog('znuny.standalone_ticket.failed', $queue, $ownerId, $customerUser, null, null, $result['errors']);
        }

        return $result;
    }
}
