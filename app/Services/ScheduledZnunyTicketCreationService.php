<?php

namespace App\Services;

use App\Models\ScheduledZnunyTask;
use App\Services\Znuny\ScheduledTicketCreationOutcome;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketAdvancedDefaultsService;
use Throwable;

class ScheduledZnunyTicketCreationService
{
    public function __construct(private ZnunyClient $client, private ZnunyTicketAdvancedDefaultsService $defaultsService) {}

    public function createTicketFromTask(ScheduledZnunyTask $task): array
    {
        $result = [
            'outcome' => ScheduledTicketCreationOutcome::NOT_SENT,
            'ticket_id' => null,
            'ticket_number' => null,
            'error_summary' => null,
            'error_details' => null,
            'response_snapshot' => null,
        ];

        // 1. Local Validation
        // Exceptions or validation failures before createTicket => NOT_SENT,
        // because the actual scheduled ticket creation request has not been sent.
        $queue = trim((string) $task->queue_name);
        $ownerId = $task->owner_id;
        $customerUser = trim((string) $task->customer_user_login);
        $title = trim((string) $task->subject);
        $body = trim((string) $task->body);

        if (empty($queue) || empty($ownerId) || empty($customerUser)) {
            $result['error_summary'] = 'Missing required Owner, Queue, or CustomerUser.';
            $result['error_details'] = 'Task is missing queue_name, owner_id, or customer_user_login.';

            return $result;
        }

        if (empty($title) || empty($body)) {
            $result['error_summary'] = 'Ticket title and article body are required.';
            $result['error_details'] = 'Task is missing subject or body.';

            return $result;
        }

        // 2. Safe Pre-flight Lookups (Not Sent on failure/timeout)
        try {
            $customerData = $this->client->getCustomerUser($customerUser);
            if (! $customerData['found']) {
                $result['error_summary'] = 'Failed to resolve CustomerUser: '.$customerUser;
                $result['error_details'] = 'CustomerUser not found in Znuny.';

                return $result;
            }

            $customerId = $customerData['customer_id'] ?? $customerData['user_customer_id'] ?? null;
            if (empty($customerId)) {
                $result['error_summary'] = "CustomerUser '{$customerUser}' has no CustomerID/UserCustomerID assigned.";
                $result['error_details'] = 'Customer returned from Znuny but lacks an ID.';

                return $result;
            }

            $defaults = $this->defaultsService->getDefaults();

            $state = trim((string) $task->state_name) ?: $defaults['state'];
            $priority = trim((string) $task->priority_name) ?: $defaults['priority'];
            $lock = trim((string) $task->lock_name) ?: $defaults['lock'];
            $type = trim((string) $task->type_name) ?: null;
            $service = trim((string) $task->service_name) ?: null;
            $sla = trim((string) $task->sla_name) ?: null;

            if (empty($state) || empty($priority)) {
                $result['error_summary'] = 'State and Priority are required by Znuny API.';
                $result['error_details'] = 'No state or priority on task and no fallback default found.';

                return $result;
            }

            $validationPayload = [
                'OwnerID' => (int) $ownerId,
                'Queue' => $queue,
                'CustomerUser' => $customerUser,
                'CustomerID' => $customerId,
                'State' => $state,
                'Priority' => $priority,
                'Lock' => $lock,
            ];

            if ($type) {
                $validationPayload['Type'] = $type;
            }
            if ($service) {
                $validationPayload['Service'] = $service;
            }
            if ($sla) {
                $validationPayload['SLA'] = $sla;
            }

            $validation = $this->client->validateTicketCreate($validationPayload);
            if (! $validation['valid']) {
                $result['outcome'] = ScheduledTicketCreationOutcome::FAILED;
                $result['error_summary'] = 'Znuny ticket validation failed.';
                $result['error_details'] = json_encode($validation['errors'] ?? []);
                $result['response_snapshot'] = $this->sanitizeResponse($validation);

                return $result;
            }
        } catch (Throwable $e) {
            $result['outcome'] = ScheduledTicketCreationOutcome::NOT_SENT;
            $result['error_summary'] = 'Pre-flight check failed: '.substr($e->getMessage(), 0, 150);
            $result['error_details'] = $e->getMessage()."\n".$e->getTraceAsString();

            return $result;
        }

        // 3. Create Ticket
        // Exceptions during/after createTicket call => UNCERTAIN,
        // because the create request may have reached Znuny and we didn't get a clear response.
        $ticketPayload = [
            'Title' => $title,
            'Queue' => $queue,
            'CustomerUser' => $customerUser,
            'CustomerID' => $customerId,
            'State' => $state,
            'OwnerID' => (int) $ownerId,
            'Priority' => $priority,
            'Lock' => $lock,
        ];

        if ($type) {
            $ticketPayload['Type'] = $type;
        }
        if ($service) {
            $ticketPayload['Service'] = $service;
        }
        if ($sla) {
            $ticketPayload['SLA'] = $sla;
        }

        $payload = [
            'Ticket' => $ticketPayload,
            'Article' => [
                'Subject' => $title,
                'Body' => $body,
                'ContentType' => 'text/plain; charset=utf8',
                'IsVisibleForCustomer' => 1,
            ],
        ];

        try {
            $apiResult = $this->client->createTicket($payload);
            $result['response_snapshot'] = $this->sanitizeResponse($apiResult['raw'] ?? $apiResult);

            if ($apiResult['success'] === true && ! empty($apiResult['ticket_id']) && ! empty($apiResult['ticket_number'])) {
                $result['outcome'] = ScheduledTicketCreationOutcome::SUCCESS;
                $result['ticket_id'] = $apiResult['ticket_id'];
                $result['ticket_number'] = $apiResult['ticket_number'];
            } else {
                $result['outcome'] = ScheduledTicketCreationOutcome::FAILED;
                $result['error_summary'] = 'Znuny API returned success false or missing ID/Number.';
                $result['error_details'] = json_encode($apiResult['errors'] ?? []);
            }
        } catch (Throwable $e) {
            $result['outcome'] = ScheduledTicketCreationOutcome::UNCERTAIN;
            $result['error_summary'] = 'Exception during ticket creation HTTP request: '.substr($e->getMessage(), 0, 150);
            $result['error_details'] = $e->getMessage()."\n".$e->getTraceAsString();
        }

        return $result;
    }

    private function sanitizeResponse(array $response): array
    {
        array_walk_recursive($response, function (&$value, $key) {
            if (! is_string($key)) {
                return;
            }
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, ['userlogin', 'password', 'token', 'sessionid', 'authorization'])) {
                $value = '[REDACTED]';
            }
        });

        return $response;
    }
}
