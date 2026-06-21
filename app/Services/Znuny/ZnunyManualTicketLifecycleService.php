<?php

namespace App\Services\Znuny;

use App\Models\ZabbixTicket;
use App\Services\SettingsService;
use App\Services\Zabbix\ZabbixProblemCache;
use Illuminate\Support\Carbon;

class ZnunyManualTicketLifecycleService
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_RESOLVED_WAITING = 'resolved_waiting';

    public const STATUS_CLOSE_CANDIDATE = 'close_candidate';

    public const STATUS_FLAPPING = 'flapping';

    public const STATUS_REOPEN_CANDIDATE = 'reopen_candidate';

    public const STATUS_REOPENED = 'reopened';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const STATUS_CACHE_STALE = 'cache_stale';

    public const STATUS_IDENTITY_MISSING = 'identity_missing';

    public function __construct(
        protected ZabbixProblemCache $cache
    ) {}

    public function evaluate(?int $ticketId = null): array
    {
        $query = ZabbixTicket::whereNotNull('znuny_ticket_id')
            ->where('creation_source', 'manual');

        if ($ticketId) {
            $query->where('id', $ticketId);
        }

        $tickets = $query->get();

        $stats = [
            'scanned' => 0,
            'active' => 0,
            'resolved_waiting' => 0,
            'close_candidate' => 0,
            'flapping' => 0,
            'reopen_candidate' => 0,
            'reopened' => 0,
            'closed' => 0,
            'identity_missing' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $closeDelayHours = SettingsService::int('default_close_delay_hours', 4);
        $flapThreshold = SettingsService::int('manual_ticket_flap_threshold', 3);
        $flapDelayHours = SettingsService::int('manual_ticket_extra_flapping_delay_hours', 24);
        $reopenWindowHours = SettingsService::defaultReopenWindowHours();

        $now = Carbon::now();
        $activeProblems = $this->cache->all();
        $lastPoll = $this->cache->lastPoll();

        $pollIntervalMinutes = SettingsService::int('zabbix_poll_interval_minutes', 1) ?? 1;
        $presenceWindowMinutes = SettingsService::int('zabbix_problem_cache_ttl_minutes', 3) ?? 3;
        $maxStaleMinutes = max($presenceWindowMinutes, 2 * $pollIntervalMinutes);

        $isCacheFresh = false;

        $lastSuccessfulAt = null;
        if ($lastPoll && isset($lastPoll['last_successful_polled_at'])) {
            $lastSuccessfulAt = $lastPoll['last_successful_polled_at'];
        } elseif ($lastPoll && isset($lastPoll['status']) && $lastPoll['status'] === 'success' && isset($lastPoll['polled_at'])) {
            $lastSuccessfulAt = $lastPoll['polled_at'];
        }

        if ($lastSuccessfulAt) {
            try {
                $polledAt = Carbon::parse($lastSuccessfulAt);
                if ($polledAt->greaterThanOrEqualTo($now->copy()->subMinutes($maxStaleMinutes))) {
                    $isCacheFresh = true;
                }
            } catch (\Throwable $e) {
                // Ignore parse error, cache is stale
            }
        }

        $stats['cache_stale'] = 0;

        foreach ($tickets as $ticket) {
            $stats['scanned']++;

            try {
                if (empty($ticket->zabbix_host_id) || empty($ticket->zabbix_trigger_id)) {
                    $ticket->manual_lifecycle_status = self::STATUS_IDENTITY_MISSING;
                    $ticket->manual_lifecycle_last_checked_at = $now;
                    $ticket->zabbix_problem_is_active = null;
                    $ticket->zabbix_problem_resolved_at = null;
                    $ticket->manual_close_eligible_at = null;
                    $ticket->save();
                    $stats['identity_missing']++;
                    $stats['skipped']++;

                    continue;
                }

                if ($ticket->znuny_ticket_state_type === 'closed') {
                    if ($ticket->manual_lifecycle_closed_at === null) {
                        $ticket->manual_lifecycle_closed_at = clone $now;
                    }

                    if ($isCacheFresh) {
                        $currentProblem = $this->findActiveProblemForTicket($ticket, $activeProblems);

                        if ($currentProblem !== null) {
                            $anchor = $ticket->manual_lifecycle_closed_at;

                            if ($anchor) {
                                $windowEnd = (clone $anchor)->addHours($reopenWindowHours);

                                if ($now->lessThanOrEqualTo($windowEnd)) {
                                    $ticket->manual_lifecycle_status = self::STATUS_REOPEN_CANDIDATE;
                                    $ticket->zabbix_problem_is_active = true;
                                    $ticket->zabbix_problem_last_seen_active_at = $now;
                                    $ticket->manual_lifecycle_last_checked_at = $now;
                                    $ticket->save();
                                    $stats['reopen_candidate']++;

                                    continue;
                                }
                            }
                        }
                    }

                    $ticket->manual_lifecycle_status = self::STATUS_CLOSED;
                    $ticket->zabbix_problem_is_active = false;
                    $ticket->manual_lifecycle_last_checked_at = $now;
                    $ticket->save();
                    $stats['closed']++;

                    continue;
                }

                if (! $isCacheFresh) {
                    $ticket->manual_lifecycle_status = self::STATUS_CACHE_STALE;
                    $ticket->manual_lifecycle_last_checked_at = $now;
                    $ticket->save();
                    $stats['cache_stale']++;
                    $stats['skipped']++;

                    continue;
                }

                $currentProblem = $this->findActiveProblemForTicket($ticket, $activeProblems);

                $activeProblem = $currentProblem !== null;
                $currentEventId = $currentProblem ? $this->extractProblemEventId($currentProblem) : null;
                $currentStartedAt = $currentProblem ? $this->extractProblemStartedAt($currentProblem) : null;

                $wasActive = $ticket->zabbix_problem_is_active === true;
                $wasResolvedLike = in_array($ticket->manual_lifecycle_status, [
                    self::STATUS_RESOLVED_WAITING,
                    self::STATUS_CLOSE_CANDIDATE,
                ], true);

                $ticket->manual_lifecycle_last_checked_at = $now;

                $isActiveNow = $activeProblem;

                if ($isActiveNow) {
                    $isRealReturnFromResolved = $isActiveNow && ! $wasActive && $wasResolvedLike;

                    $ticket->zabbix_problem_is_active = true;
                    $ticket->zabbix_problem_last_seen_active_at = $now;

                    if ($isRealReturnFromResolved) {
                        $isLegitimateFlap = $this->isLegitimateFlap($ticket, $currentEventId, $currentStartedAt);

                        if ($isLegitimateFlap) {
                            $ticket->manual_flap_count++;
                            $ticket->zabbix_last_counted_flap_event_id = $currentEventId;
                            $ticket->zabbix_last_counted_flap_started_at = $currentStartedAt;
                            $ticket->manual_last_flap_counted_at = $now;

                            if ($flapThreshold > 0 && $ticket->manual_flap_count >= $flapThreshold) {
                                $ticket->manual_flapping_detected_at = $ticket->manual_flapping_detected_at ?? $now;
                            }
                        }

                        $ticket->zabbix_problem_resolved_at = null;
                        $ticket->manual_close_eligible_at = null;
                    } else {
                        // Repeated active -> active evaluation or return from non-trusted resolved state
                        // Must not increment flap count. Make sure resolved_at is cleared.
                        if ($ticket->zabbix_problem_resolved_at !== null) {
                            $ticket->zabbix_problem_resolved_at = null;
                            $ticket->manual_close_eligible_at = null;
                        }
                    }

                    if ($flapThreshold > 0 && $ticket->manual_flap_count >= $flapThreshold) {
                        $ticket->manual_lifecycle_status = self::STATUS_FLAPPING;
                        $stats['flapping']++;
                    } else {
                        // Self-healing logic for active tickets that shouldn't be flapping
                        if ($ticket->manual_lifecycle_status === self::STATUS_FLAPPING) {
                            $ticket->manual_flapping_detected_at = null;
                        }
                        if ($ticket->manual_lifecycle_status === self::STATUS_REOPENED) {
                            $ticket->manual_lifecycle_status = self::STATUS_REOPENED;
                            $stats['reopened']++;
                        } else {
                            $ticket->manual_lifecycle_status = self::STATUS_ACTIVE;
                            $stats['active']++;
                        }
                    }

                    $ticket->save();

                    continue;
                }

                // Problem is not active (resolved)
                $ticket->zabbix_problem_is_active = false;

                if ($ticket->zabbix_problem_resolved_at === null) {
                    $ticket->zabbix_problem_resolved_at = $now;
                }

                $delayHours = $closeDelayHours;
                if ($flapThreshold > 0 && $ticket->manual_flap_count >= $flapThreshold) {
                    $delayHours += $flapDelayHours;
                }

                $ticket->manual_close_eligible_at = (clone $ticket->zabbix_problem_resolved_at)->addHours($delayHours);

                if ($now->greaterThanOrEqualTo($ticket->manual_close_eligible_at)) {
                    $ticket->manual_lifecycle_status = self::STATUS_CLOSE_CANDIDATE;
                    $stats['close_candidate']++;
                } else {
                    $ticket->manual_lifecycle_status = self::STATUS_RESOLVED_WAITING;
                    $stats['resolved_waiting']++;
                }

                $ticket->save();
            } catch (\Throwable $e) {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    private function findActiveProblemForTicket(ZabbixTicket $ticket, array $activeProblems): ?array
    {
        foreach ($activeProblems as $problem) {
            $hostId = $problem['hosts'][0]['hostid'] ?? ($problem['hostid'] ?? null);
            $triggerId = $problem['objectid'] ?? ($problem['triggerid'] ?? null);

            if ($hostId == $ticket->zabbix_host_id && $triggerId == $ticket->zabbix_trigger_id) {
                return $problem;
            }
        }

        if ($ticket->zabbix_event_id) {
            $individualProblem = $this->cache->find($ticket->zabbix_event_id);
            if ($individualProblem) {
                return $individualProblem;
            }
        }

        return null;
    }

    private function extractProblemEventId(array $problem): ?string
    {
        $eventId = (string) ($problem['eventid'] ?? '');

        return $eventId === '' ? null : $eventId;
    }

    private function extractProblemStartedAt(array $problem): ?Carbon
    {
        if (! empty($problem['clock'])) {
            return Carbon::createFromTimestamp($problem['clock']);
        }

        if (! empty($problem['started_at'])) {
            try {
                return Carbon::parse($problem['started_at']);
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return null;
    }

    private function isLegitimateFlap(ZabbixTicket $ticket, ?string $currentEventId, ?Carbon $currentStartedAt): bool
    {
        if ($currentStartedAt === null) {
            return false; // Reliable timestamp is mandatory
        }

        $isGenuinelyNew = false;
        if ($currentEventId && $ticket->zabbix_event_id && $currentEventId !== $ticket->zabbix_event_id) {
            $isGenuinelyNew = true;
        }
        if ($ticket->zabbix_started_at && $currentStartedAt->toDateTimeString() !== $ticket->zabbix_started_at->toDateTimeString()) {
            $isGenuinelyNew = true;
        }

        $isNewer = true;
        if ($ticket->zabbix_started_at && $currentStartedAt->lessThanOrEqualTo($ticket->zabbix_started_at)) {
            $isNewer = false;
        }
        if ($ticket->created_at && $currentStartedAt->lessThanOrEqualTo($ticket->created_at)) {
            $isNewer = false;
        }
        if ($ticket->zabbix_problem_resolved_at && $currentStartedAt->lessThanOrEqualTo($ticket->zabbix_problem_resolved_at)) {
            $isNewer = false;
        }

        $notAlreadyCounted = true;
        if ($currentEventId && $ticket->zabbix_last_counted_flap_event_id && $currentEventId === $ticket->zabbix_last_counted_flap_event_id) {
            $notAlreadyCounted = false;
        }
        if ($ticket->zabbix_last_counted_flap_started_at && $currentStartedAt->lessThanOrEqualTo($ticket->zabbix_last_counted_flap_started_at)) {
            $notAlreadyCounted = false;
        }

        return $isGenuinelyNew && $isNewer && $notAlreadyCounted;
    }
}
