<?php

namespace App\Support\Pagination;

use App\Services\SettingsService;

class PaginationSettings
{
    /**
     * Get the base per page value (N).
     * Falls back to 100 if missing or invalid.
     * Must be an integer greater than 10.
     */
    public function basePerPage(): int
    {
        // Use SettingsService::string to get the raw value, then strictly parse it
        $rawValue = SettingsService::string('pagination_per_page_base');

        $value = $this->parseStrictInt($rawValue);

        if ($value === null || $value <= 10) {
            return 100; // default N
        }

        return $value;
    }

    /**
     * Get the default per page value.
     */
    public function defaultPerPage(): int
    {
        return $this->basePerPage();
    }

    /**
     * Get the available per page options.
     * Generates options based on N:
     * - ceil(N / 10) * 5
     * - N
     * - 2N
     * - 3N
     *
     * @return int[]
     */
    public function perPageOptions(): array
    {
        $n = $this->basePerPage();

        $lower = (int) ceil($n / 10) * 5;

        $options = [
            $lower,
            $n,
            $n * 2,
            $n * 3,
        ];

        // Ensure unique, positive, sorted
        $options = array_filter($options, fn ($opt) => $opt > 0);
        $options = array_unique($options);
        sort($options);

        return $options;
    }

    /**
     * Normalize a selected per page value.
     * Returns the selected value if it is one of the generated options,
     * otherwise returns the default base value N.
     */
    public function normalizePerPage(int|string|null $value): int
    {
        $parsed = $this->parseStrictInt($value);

        if ($parsed === null || $parsed <= 0) {
            return $this->defaultPerPage();
        }

        $options = $this->perPageOptions();

        if (in_array($parsed, $options, true)) {
            return $parsed;
        }

        return $this->defaultPerPage();
    }

    /**
     * Strictly parse a value as an integer.
     * Rejects float-like strings, alphanumeric strings, etc.
     */
    private function parseStrictInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return null;
            }

            // Must match exactly an optional minus sign followed by digits
            if (preg_match('/^-?\d+$/', $trimmed)) {
                return (int) $trimmed;
            }
        }

        return null;
    }
}
