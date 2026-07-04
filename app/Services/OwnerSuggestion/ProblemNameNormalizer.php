<?php

namespace App\Services\OwnerSuggestion;

class ProblemNameNormalizer
{
    public function normalize(?string $problemName): string
    {
        if (empty($problemName)) {
            return '';
        }

        $normalized = mb_strtolower($problemName);

        // Percentages
        $normalized = preg_replace('/\d+(?:\.\d+)?\s*%/', '<percent>', $normalized);

        // IPv4
        $normalized = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '<ip>', $normalized);

        // MAC
        $normalized = preg_replace('/\b(?:[0-9a-f]{2}[:-]){5}[0-9a-f]{2}\b/i', '<mac>', $normalized);

        // UUID
        $normalized = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '<id>', $normalized);

        // Hex/Hash (16+ chars)
        $normalized = preg_replace('/\b[0-9a-f]{16,}\b/i', '<id>', $normalized);

        // Dates (YYYY-MM-DD, MM/DD/YYYY, YYYY/MM/DD)
        $normalized = preg_replace('/\b\d{4}[-\/]\d{2}[-\/]\d{2}\b/', '<date>', $normalized);
        $normalized = preg_replace('/\b\d{2}[-\/]\d{2}[-\/]\d{4}\b/', '<date>', $normalized);

        // Times (HH:MM:SS or HH:MM)
        $normalized = preg_replace('/\b\d{2}:\d{2}(?::\d{2})?\b/', '<time>', $normalized);

        // Windows paths (e.g. C:\Windows\Temp)
        $normalized = preg_replace('/\b[a-z]:\\\\[^\s]*\b/i', '<path>', $normalized);

        // Linux paths (must have at least one internal slash to avoid matching random words)
        // Examples: /var/log, /etc/passwd, /opt/app/data
        $normalized = preg_replace('/(?:\/[a-z0-9_.-]+)+\/?/i', '<path>', $normalized);

        // Numbers (standalone, integer or decimal)
        $normalized = preg_replace('/\b\d+(?:\.\d+)?\b/', '<num>', $normalized);

        // Normalize meaningless punctuation by replacing with space
        // We keep letters, digits, our placeholder brackets <>, and spaces.
        $normalized = preg_replace('/[^a-z0-9<>\s]/', ' ', $normalized);

        // Normalize whitespace
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }
}
