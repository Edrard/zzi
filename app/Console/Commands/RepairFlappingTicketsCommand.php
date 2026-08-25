<?php

namespace App\Console\Commands;

use App\Models\ZabbixTicket;
use App\Services\Zabbix\ZabbixProblemCache;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RepairFlappingTicketsCommand extends Command
{
    protected $signature = 'znuny:repair-flapping-tickets {--dry-run : Only list affected tickets without modifying them} {--include-active-counts : Also reset polluted manual_flap_count on active non-flapping tickets} {--backfill-started-at : Backfill missing zabbix_started_at from current Zabbix cache if available}';

    protected $description = 'Repair manually created tickets that were incorrectly marked as flapping due to repeated evaluations without state transition.';

    private function extractProblemStartedAt(array $problem): ?Carbon
    {
        if (! empty($problem['clock'])) {
            return Carbon::createFromTimestamp($problem['clock']);
        }
        if (! empty($problem['started_at'])) {
            try {
                return Carbon::parse($problem['started_at']);
            } catch (\Throwable $e) {
            }
        }

        return null;
    }

    public function handle(ZabbixProblemCache $cache)
    {
        $isDryRun = $this->option('dry-run');
        $includeActiveCounts = $this->option('include-active-counts');
        $backfillStartedAt = $this->option('backfill-started-at');

        if ($backfillStartedAt) {
            return $this->handleBackfill($cache, $isDryRun);
        }

        if ($isDryRun) {
            $this->info('DRY RUN: Identifying polluted flapping tickets...');
        } else {
            $this->info('Repairing polluted flapping tickets...');
        }

        $query = ZabbixTicket::where('creation_source', 'manual')
            ->where('zabbix_problem_is_active', true)
            ->whereNotIn('manual_lifecycle_status', ['closed', 'identity_missing']);

        $query->where(function ($q) use ($includeActiveCounts) {
            $q->where('manual_lifecycle_status', 'flapping');
            if ($includeActiveCounts) {
                $q->orWhere('manual_flap_count', '>', 0);
            }
        });

        $tickets = $query->get();
        $activeProblems = $cache->all();

        $count = 0;
        foreach ($tickets as $ticket) {
            $isFalseFlap = true;
            $reason = 'flapping_status_reset';

            if ($includeActiveCounts && $ticket->manual_lifecycle_status !== 'flapping') {
                $currentProblem = null;
                foreach ($activeProblems as $p) {
                    $hostId = $p['hosts'][0]['hostid'] ?? ($p['hostid'] ?? null);
                    $triggerId = $p['objectid'] ?? ($p['triggerid'] ?? null);
                    if ($hostId == $ticket->zabbix_host_id && $triggerId == $ticket->zabbix_trigger_id) {
                        $currentProblem = $p;
                        break;
                    }
                }

                if (! $currentProblem && $ticket->zabbix_event_id) {
                    $currentProblem = $cache->find($ticket->zabbix_event_id);
                }

                if ($currentProblem) {
                    $currentEventId = (string) ($currentProblem['eventid'] ?? '');
                    $currentStartedAt = $this->extractProblemStartedAt($currentProblem);

                    if (! $currentStartedAt) {
                        // Conservative fallback: without reliable timestamp, assume not a false flap for active counts
                        $isFalseFlap = false;
                        $reason = 'missing_started_at';
                    } else {
                        $isGenuinelyNew = false;
                        if ($currentEventId && $currentEventId !== $ticket->zabbix_event_id) {
                            $isGenuinelyNew = true;
                        }
                        if ($currentStartedAt && $ticket->zabbix_started_at && $currentStartedAt->toDateTimeString() !== $ticket->zabbix_started_at->toDateTimeString()) {
                            $isGenuinelyNew = true;
                        }

                        if ($isGenuinelyNew) {
                            $isFalseFlap = false;
                        } else {
                            $reason = 'same_occurrence';
                        }
                    }
                } else {
                    // No current problem to verify, be conservative
                    $isFalseFlap = false;
                    $reason = 'no_current_problem';
                }

                if ($isFalseFlap) {
                    $reason = 'active_polluted_count';
                }
            }

            if (! $isFalseFlap) {
                continue;
            }

            $this->info(($isDryRun ? 'Would repair' : 'Repairing')." Ticket ID {$ticket->id} (Host: {$ticket->zabbix_host_name}, Flap Count: {$ticket->manual_flap_count}) - Reason: {$reason}");

            if (! $isDryRun) {
                $ticket->manual_lifecycle_status = 'active';
                $ticket->manual_flapping_detected_at = null;
                $ticket->manual_flap_count = 0; // Reset polluted counter
                $ticket->zabbix_problem_resolved_at = null;
                $ticket->manual_close_eligible_at = null;
                $ticket->zabbix_last_counted_flap_event_id = null;
                $ticket->zabbix_last_counted_flap_started_at = null;
                $ticket->manual_last_flap_counted_at = null;
                $ticket->save();
            }
            $count++;
        }

        if ($isDryRun) {
            $this->info("Dry run complete. {$count} tickets would be repaired.");
        } else {
            $this->info("Repaired {$count} tickets.");
        }

        return self::SUCCESS;
    }

    private function handleBackfill(ZabbixProblemCache $cache, bool $isDryRun): int
    {
        if ($isDryRun) {
            $this->info('DRY RUN: Identifying tickets for backfill...');
        } else {
            $this->info('Backfilling missing zabbix_started_at...');
        }

        $tickets = ZabbixTicket::where('creation_source', 'manual')
            ->whereNull('zabbix_started_at')
            ->whereNotNull('zabbix_event_id')
            ->get();

        $count = 0;
        foreach ($tickets as $ticket) {
            $problem = $cache->find($ticket->zabbix_event_id);
            if (! $problem) {
                continue;
            }

            $startedAt = $this->extractProblemStartedAt($problem);
            if (! $startedAt) {
                continue;
            }

            $this->info(($isDryRun ? 'Would backfill' : 'Backfilling')." Ticket ID {$ticket->id} (Event: {$ticket->zabbix_event_id}) with {$startedAt->toDateTimeString()}");

            if (! $isDryRun) {
                $ticket->zabbix_started_at = $startedAt;
                $ticket->save();
            }
            $count++;
        }

        if ($isDryRun) {
            $this->info("Dry run complete. {$count} tickets would be backfilled.");
        } else {
            $this->info("Backfilled {$count} tickets.");
        }

        return self::SUCCESS;
    }
}
