<?php

namespace App\Services\Znuny;

use InvalidArgumentException;

class ZnunyTicketCreationMarkerBuilder
{
    /**
     * Build a marker for a Zabbix event.
     */
    public function buildZabbixMarker(mixed $zabbixEventId): string
    {
        return '[ZBX:'.$this->normalizeId($zabbixEventId).']';
    }

    /**
     * Build a marker for a scheduled run.
     */
    public function buildScheduledMarker(mixed $scheduledRunId): string
    {
        return '[SHE:'.$this->normalizeId($scheduledRunId).']';
    }

    /**
     * Normalize the ID to a canonical positive decimal string representation.
     *
     *
     * @throws InvalidArgumentException
     */
    private function normalizeId(mixed $id): string
    {
        if (is_int($id)) {
            if ($id <= 0) {
                throw new InvalidArgumentException('ID must be a positive integer.');
            }

            return (string) $id;
        }

        if (! is_string($id)) {
            throw new InvalidArgumentException('ID must be an integer or a string.');
        }

        if ($id === '') {
            throw new InvalidArgumentException('ID cannot be empty.');
        }

        if (! ctype_digit($id)) {
            throw new InvalidArgumentException('ID string must contain only decimal digits.');
        }

        $normalized = ltrim($id, '0');

        if ($normalized === '') {
            throw new InvalidArgumentException('ID cannot be zero.');
        }

        return $normalized;
    }

    /**
     * Append the marker to the subject, ensuring it is only added once at the end.
     */
    public function appendMarker(string $subject, string $marker): string
    {
        if (trim($subject) === '') {
            throw new InvalidArgumentException('Subject cannot be empty or whitespace-only.');
        }

        $subject = rtrim($subject);

        if ($this->hasMarker($subject, $marker)) {
            $escapedMarker = preg_quote($marker, '/');

            return preg_replace('/ +('.$escapedMarker.')$/', ' $1', $subject);
        }

        return $subject.' '.$marker;
    }

    /**
     * Check whether the trimmed subject ends with the exact marker.
     */
    public function hasMarker(string $subject, string $marker): bool
    {
        if ($marker === '') {
            throw new InvalidArgumentException('Marker cannot be empty.');
        }

        $subject = rtrim($subject);

        // Require at least one non-whitespace character, followed by one or more ASCII spaces,
        // and then the literal exact marker at the end of the subject.
        $escapedMarker = preg_quote($marker, '/');

        return preg_match('/\S +('.$escapedMarker.')$/', $subject) === 1;
    }
}
