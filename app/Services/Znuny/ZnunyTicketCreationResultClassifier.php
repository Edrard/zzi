<?php

namespace App\Services\Znuny;

use App\Enums\ZnunyTicketCreationClassification;

class ZnunyTicketCreationResultClassifier
{
    public function classify(array $apiResult): ZnunyTicketCreationClassification
    {
        $successFlag = $apiResult['success'] ?? null;

        $hasValidTicketId = $this->isValidTicketId($apiResult['ticket_id'] ?? null);
        $hasValidTicketNumber = $this->isValidTicketNumber($apiResult['ticket_number'] ?? null);
        $hasIdentifiers = $hasValidTicketId || $hasValidTicketNumber;

        if ($successFlag === true) {
            if ($hasValidTicketId && $hasValidTicketNumber) {
                return ZnunyTicketCreationClassification::Success;
            }

            return ZnunyTicketCreationClassification::Uncertain;
        }

        if ($successFlag === false) {
            if ($hasIdentifiers) {
                return ZnunyTicketCreationClassification::Uncertain;
            }

            if ($this->hasMeaningfulErrors($apiResult['errors'] ?? null)) {
                return ZnunyTicketCreationClassification::ConfirmedFailed;
            }

            return ZnunyTicketCreationClassification::Uncertain;
        }

        return ZnunyTicketCreationClassification::Uncertain;
    }

    private function isValidTicketId(mixed $ticketId): bool
    {
        if (is_int($ticketId) && $ticketId > 0) {
            return true;
        }
        if (is_string($ticketId) && ctype_digit($ticketId) && (int) $ticketId > 0) {
            return true;
        }

        return false;
    }

    private function isValidTicketNumber(mixed $ticketNumber): bool
    {
        if (is_scalar($ticketNumber)) {
            return trim((string) $ticketNumber) !== '';
        }

        return false;
    }

    public function normalizeErrors(mixed $errors): array
    {
        if (empty($errors)) {
            return [];
        }

        $normalized = [];
        $this->extractErrorsRecursive($errors, $normalized);

        return array_values(array_filter(array_map('trim', $normalized), fn ($val) => $val !== ''));
    }

    private function extractErrorsRecursive(mixed $item, array &$result): void
    {
        if (is_scalar($item)) {
            $result[] = (string) $item;

            return;
        }

        if (is_array($item)) {
            foreach ($item as $value) {
                $this->extractErrorsRecursive($value, $result);
            }
        }
    }

    private function hasMeaningfulErrors(mixed $errors): bool
    {
        return count($this->normalizeErrors($errors)) > 0;
    }
}
