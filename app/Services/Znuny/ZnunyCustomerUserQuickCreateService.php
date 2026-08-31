<?php

namespace App\Services\Znuny;

use App\Services\AuditLogger;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use Illuminate\Support\Facades\Log;

class ZnunyCustomerUserQuickCreateService
{
    public function __construct(
        private ZnunyClient $znunyClient,
        private ZnunyLookupCacheReadService $lookupCache,
        private ZnunyTicketCacheReconciliationService $reconciliationService
    ) {}

    public function createCustomerUser(
        string $login,
        string $email,
        string $firstName,
        string $lastName,
        string $customerId,
        ?int $currentTicketId = null
    ): array {
        $login = trim($login);
        $email = trim($email);
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        $customerId = trim($customerId);

        if ($login === '' || $firstName === '' || $lastName === '' || $customerId === '') {
            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_quick_create.validation.required_fields_missing'),
            ];
        }

        if ($email === '') {
            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_quick_create.validation.email_required'),
            ];
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_quick_create.validation.invalid_email'),
            ];
        }

        if (! $this->lookupCache->hasCustomerCompany($customerId)) {
            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_quick_create.validation.company_unavailable'),
            ];
        }

        // 1. Pre-write check
        try {
            $lookupResult = $this->znunyClient->getCustomerUser($login);
        } catch (\Throwable $e) {
            $this->writeAuditLog('znuny.customer_user.create_failed', $login, [
                'source' => 'ticket_quick_create',
                'znuny_ticket_id' => $currentTicketId,
                'customer_user_login' => $login,
                'customer_id' => $customerId,
                'failure_stage' => 'lookup',
            ]);

            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_quick_create.errors.lookup_transport_failure'),
            ];
        }

        if ($lookupResult['found']) {
            return $this->handleExistingUser($lookupResult, $login, $currentTicketId);
        }

        // 2. Create
        $payload = [
            'Login' => $login,
            'Email' => $email,
            'FirstName' => $firstName,
            'LastName' => $lastName,
            'CustomerID' => $customerId,
        ];

        try {
            $createResult = $this->znunyClient->createCustomerUser($payload);
        } catch (\Throwable $e) {
            $this->writeAuditLog('znuny.customer_user.create_failed', $login, [
                'source' => 'ticket_quick_create',
                'znuny_ticket_id' => $currentTicketId,
                'customer_user_login' => $login,
                'customer_id' => $customerId,
                'failure_stage' => 'create',
            ]);

            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_quick_create.errors.create_transport_failure'),
            ];
        }

        // Race condition duplicate check
        if (! $createResult['created']) {
            $duplicateError = false;
            foreach ($createResult['errors'] as $error) {
                if (stripos($error, 'Duplicate') !== false || stripos($error, 'already exists') !== false) {
                    $duplicateError = true;
                    break;
                }
            }

            if ($duplicateError) {
                try {
                    $retryLookup = $this->znunyClient->getCustomerUser($login);
                } catch (\Throwable $e) {
                    $this->writeAuditLog('znuny.customer_user.create_failed', $login, [
                        'source' => 'ticket_quick_create',
                        'znuny_ticket_id' => $currentTicketId,
                        'customer_user_login' => $login,
                        'customer_id' => $customerId,
                        'failure_stage' => 'reconciliation',
                    ]);

                    return [
                        'success' => false,
                        'message' => __('zabbix_tickets.details_modal.customer_user_quick_create.errors.retry_lookup_transport_failure'),
                    ];
                }
                if ($retryLookup['found']) {
                    return $this->handleExistingUser($retryLookup, $login, $currentTicketId);
                }
            }

            $errorMsg = ! empty($createResult['errors']) ? implode(' ', $createResult['errors']) : __('zabbix_tickets.details_modal.customer_user_quick_create.errors.unknown_create_failure');
            $this->writeAuditLog('znuny.customer_user.create_failed', $login, [
                'source' => 'ticket_quick_create',
                'znuny_ticket_id' => $currentTicketId,
                'customer_user_login' => $login,
                'customer_id' => $customerId,
                'failure_stage' => 'create',
            ]);

            return [
                'success' => false,
                'message' => ! empty($createResult['errors']) ? __('zabbix_tickets.details_modal.customer_user_quick_create.errors.api_error', ['error' => $errorMsg]) : $errorMsg,
            ];
        }

        // 3. Post-create validation
        $returnedCustomerId = $createResult['customer_id'] ?? '';
        $returnedLogin = $createResult['login'] ?? '';

        if ($returnedCustomerId === '' || $returnedLogin === '') {
            $this->writeAuditLog('znuny.customer_user.create_failed', $login, [
                'source' => 'ticket_quick_create',
                'znuny_ticket_id' => $currentTicketId,
                'customer_user_login' => $login,
                'customer_id' => $customerId,
                'failure_stage' => 'response_validation',
            ]);

            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_quick_create.errors.created_identity_incomplete'),
            ];
        }

        if (! $this->lookupCache->hasCustomerCompany($returnedCustomerId)) {
            $this->writeAuditLog('znuny.customer_user.create_failed', $login, [
                'source' => 'ticket_quick_create',
                'znuny_ticket_id' => $currentTicketId,
                'customer_user_login' => $login,
                'customer_id' => $customerId,
                'failure_stage' => 'response_validation',
            ]);

            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_quick_create.errors.created_company_inactive'),
            ];
        }

        // 4. Cache reconciliation
        $this->reconciliationService->reconcileCustomerUser($login, $returnedLogin, $returnedCustomerId, $currentTicketId);

        $this->writeAuditLog('znuny.customer_user.created', $returnedLogin, [
            'source' => 'ticket_quick_create',
            'znuny_ticket_id' => $currentTicketId,
            'customer_user_login' => $returnedLogin,
            'customer_id' => $returnedCustomerId,
            'email' => $email,
        ]);

        return [
            'success' => true,
            'message' => __('zabbix_tickets.details_modal.customer_user_quick_create.notifications.created_success'),
        ];
    }

    private function handleExistingUser(array $lookupResult, string $intendedLogin, ?int $currentTicketId): array
    {
        $returnedCustomerId = $lookupResult['customer_id'] ?? '';
        $returnedLogin = $lookupResult['login'] ?? '';

        if ($returnedLogin === '' || $returnedCustomerId === '') {
            $this->writeAuditLog('znuny.customer_user.create_failed', $intendedLogin, [
                'source' => 'ticket_quick_create',
                'znuny_ticket_id' => $currentTicketId,
                'customer_user_login' => $intendedLogin,
                'failure_stage' => 'response_validation',
            ]);

            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_quick_create.validation.existing_user_incomplete'),
            ];
        }

        if ($this->lookupCache->hasCustomerCompany($returnedCustomerId)) {
            $this->reconciliationService->reconcileCustomerUser($intendedLogin, $returnedLogin, $returnedCustomerId, $currentTicketId);
            $this->writeAuditLog('znuny.customer_user.create_failed', $returnedLogin, [
                'source' => 'ticket_quick_create',
                'znuny_ticket_id' => $currentTicketId,
                'customer_user_login' => $returnedLogin,
                'customer_id' => $returnedCustomerId,
                'failure_stage' => 'already_exists',
                'failure_reason' => 'customer_user_already_exists',
            ]);

            return [
                'success' => true,
                'warning' => true,
                'message' => __('zabbix_tickets.details_modal.customer_user_quick_create.notifications.already_exists'),
            ];
        }

        $this->writeAuditLog('znuny.customer_user.create_failed', $intendedLogin, [
            'source' => 'ticket_quick_create',
            'znuny_ticket_id' => $currentTicketId,
            'customer_user_login' => $intendedLogin,
            'customer_id' => $returnedCustomerId,
            'failure_stage' => 'response_validation',
        ]);

        return [
            'success' => false,
            'message' => __('zabbix_tickets.details_modal.customer_user_quick_create.validation.existing_user_company_invalid'),
        ];
    }

    private function writeAuditLog(string $action, ?string $login, array $context): void
    {
        try {
            AuditLogger::log(
                action: $action,
                entityType: 'znuny_customer_user',
                entityId: $login,
                context: $context
            );
        } catch (\Throwable $e) {
            Log::error('Failed to write Audit Log for Znuny Quick Create', ['exception' => $e->getMessage()]);
        }
    }
}
