<?php

namespace App\Services\Znuny;

use App\Models\ZabbixTicket;
use App\Services\Zabbix\ZabbixProblemCache;
use App\Services\Zabbix\ZabbixProblemFormatter;
use Illuminate\Support\Facades\Redis;

class ZnunyTicketWorkspaceCacheReader
{
    /**
     * Read all tickets from Redis, strip prefixes, decode, enrich with ZabbixTicket metadata, and filter.
     */
    public function getTickets(array $filters = []): array
    {
        $result = $this->getTicketsPaginated($filters, 1, 99999);

        return $result['rows'] ?? [];
    }

    public function getTicketById(int|string $ticketId): ?array
    {
        $payload = Redis::get("znuny:ticket:{$ticketId}");
        if (! $payload) {
            return null;
        }

        $rawTicket = json_decode($payload, true);
        if (! is_array($rawTicket)) {
            return null;
        }

        $normalized = $this->normalizeTicket($rawTicket);
        $enrichedList = $this->enrichWithZabbixLinks([$normalized]);

        return $enrichedList[0] ?? $normalized;
    }

    public function getTicketsPaginated(
        array $filters = [],
        int $page = 1,
        int $perPage = 50,
        string $sortField = 'Changed',
        string $sortDirection = 'desc'
    ): array {
        $redis = Redis::connection();
        $stIds = null;

        // 1. Base candidate set from StateType
        $stateTypes = $filters['state_types'] ?? null;
        if (is_array($stateTypes) && ! empty($stateTypes)) {
            $stIds = [];
            foreach ($stateTypes as $st) {
                if (empty($st)) {
                    continue;
                }
                $ids = $redis->zrange('znuny:index:statetype:'.strtolower($st), 0, -1);
                $stIds = array_merge($stIds, $ids);
            }
            $stIds = array_unique($stIds);
        } else {
            $keys = $redis->keys('znuny:ticket:*');
            $prefix = config('database.redis.options.prefix', '');
            try {
                if (empty($prefix) && method_exists($redis->client(), 'getOption')) {
                    $prefix = $redis->client()->getOption(\Redis::OPT_PREFIX) ?: '';
                }
            } catch (\Throwable $e) {
            }

            $stIds = [];
            foreach ($keys as $k) {
                $unprefixed = ($prefix !== '' && str_starts_with($k, $prefix)) ? substr($k, strlen($prefix)) : $k;
                $stIds[] = str_replace('znuny:ticket:', '', $unprefixed);
            }
        }

        // 2. Build options from candidate state type set (limit to 1000 to avoid 20k mget)
        $optionIds = array_slice($stIds, 0, 1000);
        $optionKeys = array_map(fn ($id) => "znuny:ticket:{$id}", $optionIds);
        $optionTickets = [];
        if (! empty($optionKeys)) {
            $payloads = $redis->mget($optionKeys);
            foreach ($payloads as $p) {
                if ($p) {
                    $t = json_decode($p, true);
                    if (is_array($t)) {
                        $optionTickets[] = $t;
                    }
                }
            }
        }
        $filterOptions = $this->extractFilterOptions($optionTickets);

        // 3. Apply Queue & Owner index intersections
        $filteredIds = $stIds;
        if (! empty($filters['queue'])) {
            $qIds = $redis->zrange("znuny:index:queue:{$filters['queue']}", 0, -1);
            $filteredIds = array_intersect($filteredIds, $qIds);
        }
        if (! empty($filters['owner'])) {
            $oIds = $redis->zrange("znuny:index:owner:{$filters['owner']}", 0, -1);
            $filteredIds = array_intersect($filteredIds, $oIds);
        }
        if (! empty($filters['priorities']) && is_array($filters['priorities'])) {
            $pIds = [];
            foreach ($filters['priorities'] as $pId) {
                if (! empty($pId)) {
                    $ids = $redis->zrange("znuny:index:priority:{$pId}", 0, -1);
                    $pIds = array_merge($pIds, $ids);
                }
            }
            $filteredIds = array_intersect($filteredIds, array_unique($pIds));
        }

        // 4. Decide if we can paginate before mget
        $hasTextSearch = ! empty($filters['search']);
        $hasLinkFilter = ! empty($filters['link_status']) && $filters['link_status'] !== 'all';
        $needsPostFetchFilter = $hasTextSearch || $hasLinkFilter;

        $total = count($filteredIds);
        $fetchIds = array_values($filteredIds);

        if (! $needsPostFetchFilter) {
            $lastPage = max(1, (int) ceil($total / $perPage));
            if ($page > $lastPage) {
                $page = 1;
            }
            $offset = ($page - 1) * $perPage;

            // Approximate sort by ID since we lack payload
            if (strtolower($sortDirection) === 'desc') {
                rsort($fetchIds);
            } else {
                sort($fetchIds);
            }

            $fetchIds = array_slice($fetchIds, $offset, $perPage);
        }

        // 5. Fetch payload
        $tickets = [];
        $unprefixedKeys = array_map(fn ($id) => "znuny:ticket:{$id}", $fetchIds);
        if (! empty($unprefixedKeys)) {
            // TODO: Guard for huge sets if text search over closed tickets is needed in future
            if ($needsPostFetchFilter && count($unprefixedKeys) > 5000) {
                $unprefixedKeys = array_slice($unprefixedKeys, 0, 5000);
            }

            $payloads = $redis->mget($unprefixedKeys);
            foreach ($payloads as $payload) {
                if ($payload) {
                    $ticket = json_decode($payload, true);
                    if (is_array($ticket) && isset($ticket['TicketID'])) {
                        $tickets[] = $this->normalizeTicket($ticket);
                    }
                }
            }
        }

        $tickets = $this->enrichWithZabbixLinks($tickets);

        if ($needsPostFetchFilter) {
            $tickets = $this->applyFilters($tickets, $filters);
            $total = count($tickets);
        }

        usort($tickets, function ($a, $b) use ($sortField, $sortDirection) {
            $valA = $a[$sortField] ?? null;
            $valB = $b[$sortField] ?? null;
            if ($valA === $valB) {
                return 0;
            }
            $cmp = $valA <=> $valB;

            return strtolower($sortDirection) === 'asc' ? $cmp : -$cmp;
        });

        if ($needsPostFetchFilter) {
            $lastPage = max(1, (int) ceil($total / $perPage));
            if ($page > $lastPage) {
                $page = 1;
            }
            $offset = ($page - 1) * $perPage;
            $rows = array_slice($tickets, $offset, $perPage);
        } else {
            $lastPage = max(1, (int) ceil($total / $perPage));
            $rows = $tickets;
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
            'filter_options' => $filterOptions,
        ];
    }

    protected function extractFilterOptions(array $tickets): array
    {
        $queues = [];
        $owners = [];
        $priorities = [];

        foreach ($tickets as $ticket) {
            if (! empty($ticket['QueueID'])) {
                $queues[$ticket['QueueID']] = $ticket['Queue'] ?? ('Queue '.$ticket['QueueID']);
            }
            if (! empty($ticket['OwnerID'])) {
                $owners[$ticket['OwnerID']] = $ticket['Owner'] ?? ('Owner '.$ticket['OwnerID']);
            }
            if (! empty($ticket['PriorityID'])) {
                $priorities[$ticket['PriorityID']] = $ticket['Priority'] ?? ('Priority '.$ticket['PriorityID']);
            }
        }

        asort($queues);
        asort($owners);
        asort($priorities);

        return [
            'queues' => $queues,
            'owners' => $owners,
            'priorities' => $priorities,
            'link_status' => [
                'all' => 'All tickets',
                'linked' => 'Linked to Zabbix problem',
                'linked_active' => 'Linked to active problem',
                'linked_resolved' => 'Linked to resolved/recovered problem',
                'unlinked' => 'Unlinked tickets',
            ],
            'state_types' => [
                'new' => 'New',
                'open' => 'Open',
                'pending reminder' => 'Pending Reminder',
                'pending auto' => 'Pending Auto',
                'closed' => 'Closed',
                'merged' => 'Merged',
                'removed' => 'Removed',
            ],
        ];
    }

    protected function applyFilters(array $tickets, array $filters): array
    {
        $filtered = [];
        $search = strtolower($filters['search'] ?? '');
        $linkFilter = $filters['link_status'] ?? 'all';
        $stateTypes = $filters['state_types'] ?? null;
        $queue = $filters['queue'] ?? null;
        $owner = $filters['owner'] ?? null;

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

            if ($linkFilter === 'linked' && ! $ticket['is_linked_to_zabbix_problem']) {
                continue;
            }
            if ($linkFilter === 'unlinked' && $ticket['is_linked_to_zabbix_problem']) {
                continue;
            }
            if ($linkFilter === 'linked_active' && ! $ticket['linked_problem_is_active']) {
                continue;
            }
            if ($linkFilter === 'linked_resolved' && ! $ticket['linked_problem_is_resolved']) {
                continue;
            }

            if (is_array($stateTypes) && ! empty($stateTypes)) {
                $matchesState = false;
                foreach ($stateTypes as $st) {
                    if (strtolower($ticket['StateType'] ?? '') === strtolower($st)) {
                        $matchesState = true;
                        break;
                    }
                }
                if (! $matchesState) {
                    continue;
                }
            }

            if ($queue && (string) ($ticket['QueueID'] ?? '') !== (string) $queue) {
                continue;
            }
            if ($owner && (string) ($ticket['OwnerID'] ?? '') !== (string) $owner) {
                continue;
            }

            $filtered[] = $ticket;
        }

        return $filtered;
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

            'is_linked_to_zabbix_problem' => false,
            'linked_problem_status' => null,
            'linked_problem_event_id' => null,
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
        if (empty($tickets)) {
            return [];
        }

        $ticketIds = array_column($tickets, 'TicketID');
        $links = ZabbixTicket::whereIn('znuny_ticket_id', $ticketIds)
            ->get()
            ->keyBy('znuny_ticket_id');

        if ($links->isEmpty()) {
            return $tickets;
        }

        $cache = app(ZabbixProblemCache::class);
        $formatter = app(ZabbixProblemFormatter::class);

        foreach ($tickets as &$t) {
            $id = $t['TicketID'] ?? null;
            if (! $id || ! $links->has($id)) {
                continue;
            }

            $link = $links->get($id);
            $t['is_linked_to_zabbix_problem'] = true;
            $t['linked_problem_status'] = $link->manual_lifecycle_status;
            $t['linked_problem_event_id'] = $link->zabbix_event_id;

            $eventId = $link->zabbix_event_id;
            $problem = $eventId ? $cache->find($eventId) : null;

            if ($problem) {
                $t['linked_problem_is_active'] = true;
                $t['linked_problem_is_resolved'] = false;
                $t['linked_problem_has_warning'] = true;
                $t['linked_problem_summary'] = $problem['name'] ?? null;
                $t['linked_problem_host'] = $problem['host_name'] ?? null;
                $t['linked_problem_severity'] = $problem['severity'] ?? null;

                $ageSecs = $formatter->getProblemAgeSeconds($problem);
                $t['linked_problem_age_label'] = $formatter->formatAge($ageSecs);
            } else {
                $t['linked_problem_is_active'] = false;
                $t['linked_problem_is_resolved'] = true;
                $t['linked_problem_has_warning'] = false;
                $t['linked_problem_summary'] = $link->zabbix_problem_name;
                $t['linked_problem_host'] = $link->zabbix_host_name;
            }
        }
        unset($t);

        return $tickets;
    }
}
