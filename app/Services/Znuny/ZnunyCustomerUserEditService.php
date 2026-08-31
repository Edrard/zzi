<?php

namespace App\Services\Znuny;

use App\Services\AuditLogger;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use Illuminate\Support\Facades\Log;

class ZnunyCustomerUserEditService
{
    private const MUTABLE_FIELDS = [
        'Login',
        'Email',
        'FirstName',
        'LastName',
        'CustomerID',
    ];

    public function __construct(
        private ZnunyClient $znunyClient,
        private ZnunyLookupCacheReadService $lookupCache,
        private ZnunyTicketCacheReconciliationService $reconciliationService
    ) {}

    public function getCustomerUser(string $login): array
    {
        $requestedLogin = trim($login);

        if ($requestedLogin === '') {
            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_edit.errors.not_found'),
            ];
        }

        try {
            $lookupResult = $this->znunyClient->getCustomerUser($requestedLogin);
        } catch (\Throwable) {
            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_edit.errors.lookup_transport_failure'),
            ];
        }

        if (! ($lookupResult['found'] ?? false)) {
            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_edit.errors.not_found'),
            ];
        }

        $authoritativeLogin = trim((string) ($lookupResult['login'] ?? ''));

        if (
            $authoritativeLogin === ''
            || strcasecmp($authoritativeLogin, $requestedLogin) !== 0
        ) {
            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_edit.errors.lookup_identity_invalid'),
            ];
        }

        return [
            'success' => true,
            'data' => [
                'login' => $authoritativeLogin,
                'email' => trim((string) ($lookupResult['email'] ?? '')),
                'first_name' => trim((string) ($lookupResult['first_name'] ?? '')),
                'last_name' => trim((string) ($lookupResult['last_name'] ?? '')),
                'customer_id' => trim((string) ($lookupResult['customer_id'] ?? '')),
            ],
        ];
    }

    public function updateCustomerUser(
        string $login,
        array $submitted,
        ?int $currentTicketId = null
    ): array {
        $requestedLogin = trim($login);

        if ($requestedLogin === '') {
            $this->writeFailureAudit(
                login: null,
                currentTicketId: $currentTicketId,
                changedFields: [],
                failureStage: 'validation',
                failureReason: 'login_required',
            );

            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_edit.errors.not_found'),
            ];
        }

        try {
            $lookupResult = $this->znunyClient->getCustomerUser($requestedLogin);
        } catch (\Throwable) {
            $this->writeFailureAudit(
                login: $requestedLogin,
                currentTicketId: $currentTicketId,
                changedFields: [],
                failureStage: 'lookup',
                failureReason: 'lookup_transport_failure',
            );

            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_edit.errors.lookup_transport_failure'),
            ];
        }

        if (! ($lookupResult['found'] ?? false)) {
            $this->writeFailureAudit(
                login: $requestedLogin,
                currentTicketId: $currentTicketId,
                changedFields: [],
                failureStage: 'lookup',
                failureReason: 'customer_user_not_found',
            );

            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_edit.errors.not_found'),
            ];
        }

        $authoritativeLogin = trim((string) ($lookupResult['login'] ?? ''));

        if (
            $authoritativeLogin === ''
            || strcasecmp($authoritativeLogin, $requestedLogin) !== 0
        ) {
            $this->writeFailureAudit(
                login: $requestedLogin,
                currentTicketId: $currentTicketId,
                changedFields: [],
                failureStage: 'lookup',
                failureReason: 'lookup_identity_invalid',
            );

            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_edit.errors.lookup_identity_invalid'),
            ];
        }

        $oldValues = [
            'Login' => $authoritativeLogin,
            'Email' => trim((string) ($lookupResult['email'] ?? '')),
            'FirstName' => trim((string) ($lookupResult['first_name'] ?? '')),
            'LastName' => trim((string) ($lookupResult['last_name'] ?? '')),
            'CustomerID' => trim((string) ($lookupResult['customer_id'] ?? '')),
        ];

        $newValues = $oldValues;

        foreach (self::MUTABLE_FIELDS as $field) {
            if (array_key_exists($field, $submitted)) {
                $newValues[$field] = trim((string) $submitted[$field]);
            }
        }

        $validation = $this->validateValues($newValues);

        if ($validation !== null) {
            $this->writeFailureAudit(
                login: $authoritativeLogin,
                currentTicketId: $currentTicketId,
                changedFields: $this->changedFieldNames($oldValues, $newValues),
                failureStage: 'validation',
                failureReason: $validation['reason'],
            );

            return [
                'success' => false,
                'message' => $validation['message'],
            ];
        }

        if (! $this->lookupCache->hasCustomerCompany($newValues['CustomerID'])) {
            $this->writeFailureAudit(
                login: $authoritativeLogin,
                currentTicketId: $currentTicketId,
                changedFields: $this->changedFieldNames($oldValues, $newValues),
                failureStage: 'validation',
                failureReason: 'company_unavailable',
            );

            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_edit.validation.company_unavailable'),
            ];
        }

        [$changedFields, $oldChanged, $newChanged] = $this->buildChanges($oldValues, $newValues);

        if ($changedFields === []) {
            $this->writeSuccessAudit(
                login: $authoritativeLogin,
                currentTicketId: $currentTicketId,
                changedFields: [],
                oldValues: [],
                newValues: [],
                noChanges: true,
            );

            return [
                'success' => true,
                'no_changes' => true,
                'message' => __('zabbix_tickets.details_modal.customer_user_edit.notifications.no_changes'),
            ];
        }

        $updatePayload = [];

        foreach ($changedFields as $field) {
            $updatePayload[$field] = $newChanged[$field];
        }

        try {
            $updateResult = $this->znunyClient->updateCustomerUser($requestedLogin, $updatePayload);
        } catch (\Throwable) {
            $this->writeFailureAudit(
                login: $authoritativeLogin,
                currentTicketId: $currentTicketId,
                changedFields: $changedFields,
                failureStage: 'update',
                failureReason: 'update_transport_failure',
            );

            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_edit.errors.update_transport_failure'),
            ];
        }

        if (! ($updateResult['updated'] ?? false)) {
            $this->writeFailureAudit(
                login: $authoritativeLogin,
                currentTicketId: $currentTicketId,
                changedFields: $changedFields,
                failureStage: 'update',
                failureReason: 'api_rejected',
            );

            $errors = array_values(array_filter(
                (array) ($updateResult['errors'] ?? []),
                static fn ($error): bool => is_scalar($error) && trim((string) $error) !== '',
            ));

            return [
                'success' => false,
                'message' => $errors !== []
                    ? __('zabbix_tickets.details_modal.customer_user_edit.errors.api_error', [
                        'error' => implode(' ', array_map('strval', $errors)),
                    ])
                    : __('zabbix_tickets.details_modal.customer_user_edit.errors.update_failed'),
            ];
        }

        $returnedLogin = trim((string) ($updateResult['login'] ?? ''));
        $returnedCustomerId = trim((string) ($updateResult['customer_id'] ?? ''));

        if (
            $returnedLogin === ''
            || strcasecmp($returnedLogin, $newValues['Login']) !== 0
        ) {
            $this->writeFailureAudit(
                login: $authoritativeLogin,
                currentTicketId: $currentTicketId,
                changedFields: $changedFields,
                failureStage: 'response_validation',
                failureReason: 'updated_identity_invalid',
            );

            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_edit.errors.updated_identity_incomplete'),
            ];
        }

        if ($returnedCustomerId === '' || $returnedCustomerId !== $newValues['CustomerID']) {
            $this->writeFailureAudit(
                login: $authoritativeLogin,
                currentTicketId: $currentTicketId,
                changedFields: $changedFields,
                failureStage: 'response_validation',
                failureReason: 'updated_customer_id_mismatch',
            );

            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_edit.errors.updated_identity_incomplete'),
            ];
        }

        if (! $this->lookupCache->hasCustomerCompany($returnedCustomerId)) {
            $this->writeFailureAudit(
                login: $authoritativeLogin,
                currentTicketId: $currentTicketId,
                changedFields: $changedFields,
                failureStage: 'response_validation',
                failureReason: 'updated_company_unavailable',
            );

            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_edit.errors.updated_company_inactive'),
            ];
        }

        try {
            $this->reconciliationService->reconcileCustomerUser(
                $requestedLogin,
                $returnedLogin,
                $returnedCustomerId,
                $currentTicketId,
            );
        } catch (\Throwable) {
            $this->writeFailureAudit(
                login: $authoritativeLogin,
                currentTicketId: $currentTicketId,
                changedFields: $changedFields,
                failureStage: 'reconciliation',
                failureReason: 'cache_reconciliation_failed',
            );

            return [
                'success' => false,
                'message' => __('zabbix_tickets.details_modal.customer_user_edit.errors.reconciliation_failed'),
            ];
        }

        $this->writeSuccessAudit(
            login: $returnedLogin,
            currentTicketId: $currentTicketId,
            changedFields: $changedFields,
            oldValues: $oldChanged,
            newValues: $newChanged,
        );

        return [
            'success' => true,
            'no_changes' => false,
            'message' => __('zabbix_tickets.details_modal.customer_user_edit.notifications.updated_success'),
        ];
    }

    private function validateValues(array $values): ?array
    {
        if ($values['Email'] === '') {
            return [
                'reason' => 'email_required',
                'message' => __('zabbix_tickets.details_modal.customer_user_edit.validation.email_required'),
            ];
        }

        if (! filter_var($values['Email'], FILTER_VALIDATE_EMAIL)) {
            return [
                'reason' => 'invalid_email',
                'message' => __('zabbix_tickets.details_modal.customer_user_edit.validation.invalid_email'),
            ];
        }

        if (
            $values['Login'] === ''
            || $values['FirstName'] === ''
            || $values['LastName'] === ''
            || $values['CustomerID'] === ''
        ) {
            return [
                'reason' => 'required_fields_missing',
                'message' => __('zabbix_tickets.details_modal.customer_user_edit.validation.required_fields_missing'),
            ];
        }

        return null;
    }

    private function changedFieldNames(array $oldValues, array $newValues): array
    {
        return $this->buildChanges($oldValues, $newValues)[0];
    }

    private function buildChanges(array $oldValues, array $newValues): array
    {
        $changedFields = [];
        $oldChanged = [];
        $newChanged = [];

        foreach (self::MUTABLE_FIELDS as $field) {
            if ($oldValues[$field] === $newValues[$field]) {
                continue;
            }

            $changedFields[] = $field;
            $oldChanged[$field] = $oldValues[$field];
            $newChanged[$field] = $newValues[$field];
        }

        return [$changedFields, $oldChanged, $newChanged];
    }

    private function writeSuccessAudit(
        string $login,
        ?int $currentTicketId,
        array $changedFields,
        array $oldValues,
        array $newValues,
        bool $noChanges = false,
    ): void {
        $context = [
            'source' => 'ticket_customer_user_edit',
            'znuny_ticket_id' => $currentTicketId,
            'customer_user_login' => $login,
            'changed_fields' => $changedFields,
            'old' => $oldValues,
            'new' => $newValues,
        ];

        if ($noChanges) {
            $context['no_changes'] = true;
        }

        $this->writeAuditLog('znuny.customer_user.updated', $login, $context);
    }

    private function writeFailureAudit(
        ?string $login,
        ?int $currentTicketId,
        array $changedFields,
        string $failureStage,
        string $failureReason,
    ): void {
        $this->writeAuditLog('znuny.customer_user.update_failed', $login, [
            'source' => 'ticket_customer_user_edit',
            'znuny_ticket_id' => $currentTicketId,
            'customer_user_login' => $login,
            'changed_fields' => $changedFields,
            'failure_stage' => $failureStage,
            'failure_reason' => $failureReason,
        ]);
    }

    private function writeAuditLog(string $action, ?string $login, array $context): void
    {
        try {
            AuditLogger::log(
                action: $action,
                entityType: 'znuny_customer_user',
                entityId: $login,
                context: $context,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to write Audit Log for Znuny Customer User Edit', [
                'exception' => $e,
            ]);
        }
    }
}
