<?php

namespace App\Services\Znuny;

use App\Models\ZabbixTicket;
use App\Services\Zabbix\ZabbixProblemFormatter;
use App\Services\Zabbix\ZabbixProblemQueryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Redis;

class ZnunyTicketWorkspaceCacheReader
{
    /**
     * Read all tickets from Redis, strip prefixes, decode, enrich with ZabbixTicket metadata, and filter.
     */
    public function getTickets(array $filters = []): array
    {
        $redis = Redis::connection();
        $keys = $redis->keys('znuny:ticket:*');

        if (empty($keys)) {
            return [];
        }

        $prefix = config('database.redis.options.prefix', '');
        try {
            if (empty($prefix) && method_exists($redis->client(), 'getOption')) {
                $prefix = $redis->client()->getOption(\Redis::OPT_PREFIX) ?: '';
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        $unprefixedKeys = array_map(function ($key) use ($prefix) {
            if ($prefix !== '' && str_starts_with($key, $prefix)) {
                return substr($key, strlen($prefix));
            }

            return $key;
        }, $keys);

        $payloads = $redis->mget($unprefixedKeys);

        $tickets = [];
        foreach ($payloads as $payload) {
            if ($payload) {
                $ticket = json_decode($payload, true);
                if (is_array($ticket) && isset($ticket['TicketID'])) {
                    $tickets[] = $this->normalizeTicket($ticket);
                }
            }
        }

        $tickets = $this->enrichWithZabbixLinks($tickets);
        $tickets = $this->applyFilters($tickets, $filters);

        return $tickets;
    }

    protected function normalizeTicket(array $ticket): array
    {
        return [
            'TicketID' => $ticket['TicketID'] ?? null,
            'TicketNumber' => $ticket['TicketNumber'] ?? null,
            'Title' => $ticket['Title'] ?? null,
            'QueueID' => $ticket['QueueID'] ?? null,
            'Queue' => $ticket['Queue'] ?? null,
            'OwnerID' => $ticket['OwnerID'] ?? null,
            'Owner' => $ticket['Owner'] ?? null,
            'CustomerUserID' => $ticket['CustomerUserID'] ?? null,
            'StateID' => $ticket['StateID'] ?? null,
            'State' => $ticket['State'] ?? null,
            'StateType' => $ticket['StateType'] ?? null,
            'PriorityID' => $ticket['PriorityID'] ?? null,
            'Priority' => $ticket['Priority'] ?? null,
            'TypeID' => $ticket['TypeID'] ?? null,
            'Type' => $ticket['Type'] ?? null,
            'Created' => $ticket['Created'] ?? null,
            'Changed' => $ticket['Changed'] ?? null,
            'ArticleCount' => $ticket['ArticleCount'] ?? 0,
            'LastArticleCreated' => $ticket['LastArticleCreated'] ?? null,
            'SyncFingerprint' => $ticket['SyncFingerprint'] ?? null,
            // enrichment defaults
            'is_linked_to_zabbix_problem' => false,
            'linked_problem_status' => null,
            'linked_problem_is_active' => false,
            'linked_problem_is_resolved' => false,
            'linked_problem_has_warning' => false,
            'linked_problem_summary' => null,
            'linked_problem_host' => null,
            'linked_problem_severity' => null,
            'linked_problem_age_label' => null,
        ];
    }

    protected function enrichWithZabbixLinks(array $tickets): array
    {
        $ticketIds = array_filter(array_column($tickets, 'TicketID'));
        if (empty($ticketIds)) {
            return $tickets;
        }

        $links = ZabbixTicket::whereIn('znuny_ticket_id', $ticketIds)->get()->keyBy('znuny_ticket_id');

        // Optional: fetch active problems from ZabbixProblemCache to enrich problem details if needed
        // For Phase 1, we rely mostly on what ZabbixTicket gives us, or ZabbixProblemQueryService.
        // Actually, ZabbixTicket has manual_lifecycle_status which we can use for icons.

        $activeProblems = [];
        try {
            $problemQueryService = app(ZabbixProblemQueryService::class);
            $activeProblemsResult = $problemQueryService->query('', 'age', 'asc');
            $activeProblems = collect($activeProblemsResult['problems'])->keyBy('eventid')->toArray();
        } catch (\Throwable $e) {
        }

        foreach ($tickets as &$ticket) {
            $link = $links[$ticket['TicketID']] ?? null;
            if ($link) {
                $ticket['is_linked_to_zabbix_problem'] = true;
                $ticket['linked_problem_status'] = $link->manual_lifecycle_status;

                $eventId = $link->zabbix_event_id;
                $problem = $activeProblems[$eventId] ?? null;

                if ($problem) {
                    $ticket['linked_problem_is_active'] = true;
                    $ticket['linked_problem_summary'] = $problem['name'] ?? null;
                    $ticket['linked_problem_host'] = $problem['hosts'][0]['host'] ?? $problem['host_name'] ?? null;
                    $ticket['linked_problem_severity'] = $problem['severity'] ?? null;

                    if (isset($problem['clock'])) {
                        $ageSeconds = Carbon::parse($problem['clock'])->diffInSeconds(now());
                        $ticket['linked_problem_age_label'] = app(ZabbixProblemFormatter::class)->formatAge($ageSeconds);
                    }
                } else {
                    $ticket['linked_problem_is_resolved'] = true;
                }

                // Suspicious state warning (e.g. ticket is closed but problem is active)
                $isClosed = in_array(strtolower($ticket['StateType'] ?? ''), ['closed', 'merged'], true);
                if ($isClosed && $ticket['linked_problem_is_active']) {
                    $ticket['linked_problem_has_warning'] = true;
                }
            }
        }

        return $tickets;
    }

    protected function applyFilters(array $tickets, array $filters): array
    {
        $filtered = [];

        $search = strtolower($filters['search'] ?? '');
        $linkFilter = $filters['link_status'] ?? 'all';
        $stateType = $filters['state_type'] ?? null;

        foreach ($tickets as $ticket) {
            if ($search !== '') {
                $match = false;
                if (str_contains(strtolower($ticket['TicketNumber'] ?? ''), $search)) {
                    $match = true;
                }
                if (str_contains(strtolower($ticket['Title'] ?? ''), $search)) {
                    $match = true;
                }
                if (str_contains(strtolower($ticket['CustomerUserID'] ?? ''), $search)) {
                    $match = true;
                }

                if (! $match) {
                    continue;
                }
            }

            if ($linkFilter === 'linked') {
                if (! $ticket['is_linked_to_zabbix_problem']) {
                    continue;
                }
            } elseif ($linkFilter === 'unlinked') {
                if ($ticket['is_linked_to_zabbix_problem']) {
                    continue;
                }
            } elseif ($linkFilter === 'linked_active') {
                if (! $ticket['linked_problem_is_active']) {
                    continue;
                }
            } elseif ($linkFilter === 'linked_resolved') {
                if (! $ticket['linked_problem_is_resolved']) {
                    continue;
                }
            }

            if ($stateType && strtolower($ticket['StateType'] ?? '') !== strtolower($stateType)) {
                continue;
            }

            $filtered[] = $ticket;
        }

        return $filtered;
    }
}
