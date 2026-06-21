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
            'closed' => 0,
            'identity_missing' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $closeDelayHours = SettingsService::int('default_close_delay_hours', 4);
        $flapThreshold = SettingsService::int('manual_ticket_flap_threshold', 3);
        $flapDelayHours = SettingsService::int('manual_ticket_extra_flapping_delay_hours', 24);

        $now = Carbon::now();
        $activeProblems = $this->cache->all();
        $lastPoll = $this->cache->lastPoll();

        $pollIntervalMinutes = SettingsService::int('zabbix_poll_interval_minutes', 1) ?? 1;
        $maxStaleMinutes = max(2 * $pollIntervalMinutes, 2);

        $isCacheFresh = false;
        if ($lastPoll && isset($lastPoll['status']) && $lastPoll['status'] === 'success' && isset($lastPoll['polled_at'])) {
            try {
                $polledAt = Carbon::parse($lastPoll['polled_at']);
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
                    $ticket->manual_lifecycle_status = self::STATUS_CLOSED;
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

                $activeProblem = false;
                foreach ($activeProblems as $problem) {
                    $hostId = $problem['hosts'][0]['hostid'] ?? ($problem['hostid'] ?? null);
                    $triggerId = $problem['objectid'] ?? ($problem['triggerid'] ?? null);

                    if ($hostId == $ticket->zabbix_host_id && $triggerId == $ticket->zabbix_trigger_id) {
                        $activeProblem = true;
                        break;
                    }
                }

                $ticket->manual_lifecycle_last_checked_at = $now;

                if ($activeProblem) {
                    $wasResolved = $ticket->getOriginal('zabbix_problem_is_active') === false ||
                                   in_array($ticket->getOriginal('manual_lifecycle_status'), [self::STATUS_RESOLVED_WAITING, self::STATUS_CLOSE_CANDIDATE]);

                    $ticket->zabbix_problem_is_active = true;
                    $ticket->zabbix_problem_last_seen_active_at = $now;

                    if ($wasResolved) {
                        // Flap detected! Genuine transition from resolved/waiting to active.
                        $ticket->manual_flap_count++;
                        $ticket->zabbix_problem_resolved_at = null;
                        $ticket->manual_close_eligible_at = null;

                        if ($flapThreshold > 0 && $ticket->manual_flap_count >= $flapThreshold) {
                            $ticket->manual_flapping_detected_at = $ticket->manual_flapping_detected_at ?? $now;
                        }
                    } else {
                        // Repeated active -> active evaluation.
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
                        $ticket->manual_lifecycle_status = self::STATUS_ACTIVE;
                        $stats['active']++;
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
}
