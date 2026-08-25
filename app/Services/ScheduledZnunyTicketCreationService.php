<?php

namespace App\Services;

use App\Enums\ZnunyTicketCreationClassification;
use App\Models\ScheduledZnunyTask;
use App\Enums\ScheduledZnunyTicketCreationDispatchDecision;
use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Models\ZnunyTicketCreationAttempt;
use App\Services\Znuny\ScheduledTicketCreationOutcome;
use App\Services\Znuny\ScheduledZnunyTicketCreationDuplicateGuard;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketAdvancedDefaultsService;
use App\Services\Znuny\ZnunyTicketCreationReliabilityService;
use Illuminate\Support\Facades\DB;
use App\Services\Znuny\ScheduledZnunyTicketCreationAttemptReconciliationService;
use Throwable;

class ScheduledZnunyTicketCreationService
{
    public function __construct(
        private ZnunyClient $client,
        private ZnunyTicketAdvancedDefaultsService $defaultsService,
        private ScheduledZnunyTicketCreationDuplicateGuard $duplicateGuard,
        private ScheduledZnunyTicketCreationAttemptReconciliationService $attemptReconciliation
    ) {}


    public function createTicketFromTask(ScheduledZnunyTask $task, string|int $runId): array
    {
        $result = [
            'classification' => 'not_sent',
            'outcome' => ScheduledTicketCreationOutcome::NOT_SENT,
            'duplicate' => false,
            'recovered' => false,
            'attempt_id' => null,
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
                $reliability = app(ZnunyTicketCreationReliabilityService::class);
                $result['classification'] = ZnunyTicketCreationClassification::NotSent->value;
                $result['outcome'] = ScheduledTicketCreationOutcome::NOT_SENT;
                $result['error_summary'] = 'Znuny ticket validation failed.';
                $result['error_details'] = $reliability->safeJsonEncode($reliability->normalizedSanitizedField($validation, 'errors'));
                $result['response_snapshot'] = $reliability->sanitizedResponse($validation);

                return $result;
            }
        } catch (Throwable $e) {
            $reliability = app(ZnunyTicketCreationReliabilityService::class);
            $sanitizedMessage = $reliability->sanitizeExceptionMessage($e->getMessage());
            $result['outcome'] = ScheduledTicketCreationOutcome::NOT_SENT;
            $result['error_summary'] = 'Pre-flight check failed: '.substr($sanitizedMessage, 0, 150);
            $result['error_details'] = get_class($e).': '.substr($sanitizedMessage, 0, 150);

            return $result;
        }

        // 3. Duplicate Guard
        $guardResult = $this->duplicateGuard->determineDispatchDecision($runId);
        $decision = $guardResult['decision'];

        if ($decision === ScheduledZnunyTicketCreationDispatchDecision::ReuseConfirmed) {
            $existingAttempt = $guardResult['attempt'];
            $result['duplicate'] = true;
            $result['classification'] = 'success';
            $result['outcome'] = ScheduledTicketCreationOutcome::SUCCESS;
            $result['attempt_id'] = $existingAttempt->id;
            $result['ticket_id'] = $guardResult['ticket_id'];
            $result['ticket_number'] = $guardResult['ticket_number'];

            if ($existingAttempt->status === ZnunyTicketCreationAttemptStatus::Orphaned) {
                $wasChangedByUs = false;
                $concurrentlySafe = false;
                $lockedIdentifiers = null;

                DB::transaction(function () use ($existingAttempt, &$wasChangedByUs, &$concurrentlySafe, &$lockedIdentifiers) {
                    $locked = ZnunyTicketCreationAttempt::where('id', $existingAttempt->id)->lockForUpdate()->first();
                    if (! $locked) {
                        return;
                    }

                    $lockedIdentifiers = [
                        'attempt_id' => $locked->id,
                        'ticket_id' => $locked->ticket_id,
                        'ticket_number' => $locked->ticket_number,
                    ];

                    if ($locked->status === ZnunyTicketCreationAttemptStatus::Orphaned) {
                        $locked->status = ZnunyTicketCreationAttemptStatus::Recovered;
                        $locked->save();
                        $wasChangedByUs = true;
                        $concurrentlySafe = true;
                    } else {
                        $safeStatuses = [
                            ZnunyTicketCreationAttemptStatus::Success,
                            ZnunyTicketCreationAttemptStatus::Recovered,
                            ZnunyTicketCreationAttemptStatus::ManuallyLinked,
                        ];

                        $valid = false;
                        $ticketId = $locked->ticket_id;
                        $ticketNumber = $locked->ticket_number;
                        if ($ticketId !== null && is_numeric($ticketId) && $ticketId > 0 && $ticketNumber !== null && trim((string) $ticketNumber) !== '') {
                            $valid = true;
                        }

                        if (in_array($locked->status, $safeStatuses, true) && $valid) {
                            $concurrentlySafe = true;
                        }
                    }
                });

                if ($lockedIdentifiers !== null) {
                    $result['attempt_id'] = $lockedIdentifiers['attempt_id'];
                    $result['ticket_id'] = $lockedIdentifiers['ticket_id'];
                    $result['ticket_number'] = $lockedIdentifiers['ticket_number'];
                }

                if ($concurrentlySafe) {
                    $result['recovered'] = $wasChangedByUs;
                } else {
                    $result['classification'] = 'uncertain';
                    $result['outcome'] = ScheduledTicketCreationOutcome::UNCERTAIN;
                    $result['recovered'] = false;
                    $result['error_summary'] = 'Attempt state changed concurrently to an unsafe state.';
                    $result['error_details'] = 'Attempt state changed concurrently to an unsafe state.';
                }
            }

            return $result;
        }

        if ($decision === ScheduledZnunyTicketCreationDispatchDecision::BlockUncertain) {
            $existingAttempt = $guardResult['attempt'];
            $result['duplicate'] = true;
            $result['classification'] = 'uncertain';
            $result['outcome'] = ScheduledTicketCreationOutcome::UNCERTAIN;
            $result['attempt_id'] = $existingAttempt?->id;
            $result['ticket_id'] = $guardResult['ticket_id'];
            $result['ticket_number'] = $guardResult['ticket_number'];
            $result['error_summary'] = $guardResult['reason'];
            $result['error_details'] = $guardResult['reason'];

            return $result;
        }

        // 4. Create Ticket
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

        $reliability = app(ZnunyTicketCreationReliabilityService::class);
        $attempt = $reliability->applyMarkerAndCreateAttempt(
            'scheduled_run',
            $runId,
            $title, // Original subject
            $payload,
            null // Created by is null for scheduler
        );

        // Update the payload from the reliability service which applied the marked subject
        // For the result, we also need to return the modified payload so it can be saved in the run's payload_snapshot
        $result['payload_snapshot'] = $payload;
        $result['attempt_id'] = $attempt->id;

        try {
            $reliability->recordApiStart($attempt);
            $apiResult = $this->client->createTicket($payload);
        } catch (Throwable $e) {
            $classification = $reliability->recordApiException($attempt, $e);
            $sanitizedMessage = $reliability->sanitizeExceptionMessage($e->getMessage());
            $boundedMessage = substr($sanitizedMessage, 0, 150);
            $result['classification'] = $classification->value;
            $result['outcome'] = ScheduledTicketCreationOutcome::UNCERTAIN;
            $result['error_summary'] = 'Exception during ticket creation HTTP request: '.$boundedMessage;
            $result['error_details'] = get_class($e).': '.$boundedMessage;

            if ($classification === ZnunyTicketCreationClassification::Uncertain) {
                return $this->reconcileUncertainAttempt($result, $attempt->id);
            }

            return $result;
        }

        $classification = $reliability->recordApiResult($attempt, $apiResult);
        $result['classification'] = $classification->value;
        $result['response_snapshot'] = $reliability->sanitizedResponse($apiResult['raw'] ?? $apiResult);

        if ($classification === ZnunyTicketCreationClassification::Success) {
            $result['outcome'] = ScheduledTicketCreationOutcome::SUCCESS;
            $result['ticket_id'] = $apiResult['ticket_id'];
            $result['ticket_number'] = $apiResult['ticket_number'];
        } elseif ($classification === ZnunyTicketCreationClassification::ConfirmedFailed) {
            $result['outcome'] = ScheduledTicketCreationOutcome::FAILED;
            $result['error_summary'] = 'Znuny API explicitly rejected the request.';
            $result['error_details'] = $reliability->buildSafeErrorDetails($apiResult, $classification);
        } else {
            $result['outcome'] = ScheduledTicketCreationOutcome::UNCERTAIN;
            $result['error_summary'] = 'Znuny API returned an ambiguous or incomplete response.';
            $result['error_details'] = $reliability->buildSafeErrorDetails($apiResult, $classification);
            return $this->reconcileUncertainAttempt($result, $attempt->id);
        }

        return $result;
    }

    private function reconcileUncertainAttempt(array $result, int $attemptId): array
    {
        try {
            $reconciliation = $this->attemptReconciliation->reconcile($attemptId);

            if ($reconciliation['resolved']) {
                $result['classification'] = 'success';
                $result['outcome'] = ScheduledTicketCreationOutcome::SUCCESS;
                $result['duplicate'] = false;
                $result['recovered'] = true;
                $result['ticket_id'] = $reconciliation['ticket_id'];
                $result['ticket_number'] = $reconciliation['ticket_number'];
                return $result;
            }

            if (!empty($reconciliation['reason'])) {
                $result['error_summary'] = $reconciliation['reason'];
            }
            if (isset($reconciliation['ticket_id'])) {
                $result['ticket_id'] = $reconciliation['ticket_id'];
            }
            if (isset($reconciliation['ticket_number'])) {
                $result['ticket_number'] = $reconciliation['ticket_number'];
            }
        } catch (Throwable $e) {
            $result['error_summary'] = 'Automatic reconciliation failed after an uncertain Znuny response.';
        }

        return $result;
    }
}
