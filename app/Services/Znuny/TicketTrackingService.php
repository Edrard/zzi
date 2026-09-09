<?php

namespace App\Services\Znuny;

use App\Models\User;
use App\Models\ZnunyTicketSeenStatus;
use Illuminate\Support\Carbon;

class TicketTrackingService
{
    /**
     * @var array<string, array<string>> Cache of compiled regex patterns keyed by user and regexes hash.
     */
    protected array $compiledUserPatternsCache = [];

    /**
     * Compile a raw regex pattern with safe delimiter handling and iu modifiers.
     */
    public static function compilePattern(string $rawRegex): ?string
    {
        $trimmed = trim($rawRegex);
        if ($trimmed === '') {
            return null;
        }

        $candidateDelimiters = ['~', '#', '/', '%', '`', '!', '@', ';', '=', ','];
        $chosenDelimiter = null;
        foreach ($candidateDelimiters as $delim) {
            if (! str_contains($trimmed, $delim)) {
                $chosenDelimiter = $delim;
                break;
            }
        }

        if ($chosenDelimiter !== null) {
            $pattern = $chosenDelimiter.$trimmed.$chosenDelimiter.'iu';
        } else {
            $escaped = preg_replace_callback('/(\\\\*)(~)/', function ($matches) {
                return (strlen($matches[1]) % 2 === 0) ? $matches[1].'\\~' : $matches[0];
            }, $trimmed);
            $pattern = '~'.$escaped.'~iu';
        }

        if (@preg_match($pattern, '') === false) {
            return null;
        }

        return $pattern;
    }

    /**
     * Get compiled regex patterns for the user, cached in-memory per request.
     *
     * @return array<string>
     */
    public function getUserCompiledPatterns(User $user): array
    {
        $rawRegexes = $user->new_ticket_subject_ignore_regexes;
        if (empty($rawRegexes) || ! is_array($rawRegexes)) {
            return [];
        }

        $cacheKey = (int) $user->id.':'.md5(json_encode($rawRegexes));
        if (isset($this->compiledUserPatternsCache[$cacheKey])) {
            return $this->compiledUserPatternsCache[$cacheKey];
        }

        $compiled = [];
        foreach ($rawRegexes as $rawRegex) {
            if (! is_string($rawRegex)) {
                continue;
            }

            $trimmed = trim($rawRegex);
            if ($trimmed === '') {
                continue;
            }

            $pattern = static::compilePattern($trimmed);
            if ($pattern !== null) {
                $compiled[] = $pattern;
            }
        }

        return $this->compiledUserPatternsCache[$cacheKey] = $compiled;
    }

    /**
     * Check if the ticket subject matches any of the user's personal ignore regexes.
     */
    public function isSubjectIgnored(User $user, mixed $title): bool
    {
        if (! is_string($title) || $title === '') {
            return false;
        }

        $patterns = $this->getUserCompiledPatterns($user);
        if (empty($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            try {
                if (@preg_match($pattern, $title) === 1) {
                    return true;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return false;
    }

    /**
     * Check if tracking is enabled for the given user.
     */
    public function isTrackingEnabled(User $user): bool
    {
        return $user->track_new_tickets && $user->ticket_tracking_since !== null;
    }

    /**
     * Get the list of seen ticket IDs among the provided IDs for the user.
     */
    public function getSeenTicketIds(User $user, array $ticketIds): array
    {
        if (empty($ticketIds)) {
            return [];
        }

        return ZnunyTicketSeenStatus::where('user_id', $user->id)
            ->whereIn('znuny_ticket_id', $ticketIds)
            ->pluck('znuny_ticket_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
    }

    /**
     * Mark a ticket as seen for the user if tracking is enabled.
     */
    public function markTicketAsSeen(User $user, int $ticketId): void
    {
        if (! $this->isTrackingEnabled($user)) {
            return;
        }

        ZnunyTicketSeenStatus::insertOrIgnore([
            'user_id' => $user->id,
            'znuny_ticket_id' => $ticketId,
            'opened_at' => Carbon::now(),
        ]);
    }

    /**
     * Check if a ticket is considered "new" for the user.
     */
    public function isTicketNew(User $user, array $ticketData, array $seenIds): bool
    {
        if (! $this->isTrackingEnabled($user)) {
            return false;
        }

        if (! empty($ticketData['is_linked_to_zabbix_problem'])) {
            return false;
        }

        $ticketId = $ticketData['TicketID'] ?? null;
        if (! $ticketId) {
            return false;
        }

        if (in_array((int) $ticketId, $seenIds, true)) {
            return false;
        }

        $title = $ticketData['Title'] ?? null;
        if ($this->isSubjectIgnored($user, $title)) {
            return false;
        }

        // Compare ticket creation time with user's tracking_since and global max age
        try {
            $createdAt = isset($ticketData['Created']) ? Carbon::parse($ticketData['Created']) : null;
        } catch (\Throwable) {
            return false;
        }

        if ($createdAt) {
            $maxAgeDays = config('znuny.new_ticket_max_age_days');
            if ($maxAgeDays !== false) {
                if ($createdAt->lessThanOrEqualTo(Carbon::now()->subDays($maxAgeDays))) {
                    return false;
                }
            }

            if ($user->ticket_tracking_since && $createdAt->isAfter($user->ticket_tracking_since)) {
                return true;
            }
        }

        return false;
    }
}
