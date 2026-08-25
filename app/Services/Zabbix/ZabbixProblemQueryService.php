<?php

namespace App\Services\Zabbix;

class ZabbixProblemQueryService
{
    public function __construct(
        private ZabbixProblemCache $cache,
        private ZabbixProblemFormatter $formatter
    ) {}

    /**
     * Query, filter, and sort Zabbix problems from cache.
     *
     * @return array{problems: array<int, mixed>, total_cached_count: int}
     */
    public function query(string $search, string $sortField, string $sortDirection): array
    {
        $problems = $this->cache->all();
        $totalCachedCount = count($problems);

        if (! empty($search)) {
            $term = mb_strtolower($search);
            $problems = array_filter($problems, function ($problem) use ($term) {
                $hostMatch = mb_stripos($problem['host_name'] ?? '', $term) !== false;
                $nameMatch = mb_stripos($problem['name'] ?? '', $term) !== false;

                return $hostMatch || $nameMatch;
            });
        }

        $grouped = [];
        foreach ($problems as $problem) {
            $hostId = $problem['hosts'][0]['hostid'] ?? ($problem['hostid'] ?? null);
            $objectId = $problem['objectid'] ?? ($problem['triggerid'] ?? null);

            $hostName = $problem['host_name'] ?? '';
            $probName = $problem['name'] ?? '';

            if ($hostId && $objectId) {
                $logicalKey = "trigger:{$hostId}:{$objectId}";
            } else {
                $logicalKey = "name:{$hostName}:{$probName}";
            }

            if (! isset($grouped[$logicalKey])) {
                $grouped[$logicalKey] = $problem;
                $grouped[$logicalKey]['grouped_event_count'] = 1;
                $grouped[$logicalKey]['related_eventids'] = [$problem['eventid']];
            } else {
                if (in_array($problem['eventid'], $grouped[$logicalKey]['related_eventids'])) {
                    continue; // Skip exact duplicate eventid (cache edge case)
                }

                $grouped[$logicalKey]['grouped_event_count']++;
                $grouped[$logicalKey]['related_eventids'][] = $problem['eventid'];

                $currentSev = (int) ($grouped[$logicalKey]['severity'] ?? 0);
                $newSev = (int) ($problem['severity'] ?? 0);

                $currentAge = $this->formatter->getProblemAgeSeconds($grouped[$logicalKey]);
                $newAge = $this->formatter->getProblemAgeSeconds($problem);

                if ($newSev > $currentSev) {
                    $merged = $problem;
                    $merged['grouped_event_count'] = $grouped[$logicalKey]['grouped_event_count'];
                    $merged['related_eventids'] = $grouped[$logicalKey]['related_eventids'];
                    $grouped[$logicalKey] = $merged;
                } elseif ($newSev === $currentSev && $newAge > $currentAge) {
                    // Same severity, but this one is older, make it primary
                    $merged = $problem;
                    $merged['grouped_event_count'] = $grouped[$logicalKey]['grouped_event_count'];
                    $merged['related_eventids'] = $grouped[$logicalKey]['related_eventids'];
                    $grouped[$logicalKey] = $merged;
                }
            }
        }
        $problems = array_values($grouped);

        $direction = $sortDirection === 'asc' ? 1 : -1;

        usort($problems, function ($a, $b) use ($direction, $sortField) {
            $sevA = (int) ($a['severity'] ?? 0);
            $sevB = (int) ($b['severity'] ?? 0);

            $ageA = $this->formatter->getProblemAgeSeconds($a);
            $ageB = $this->formatter->getProblemAgeSeconds($b);

            $hostA = mb_strtolower($a['host_name'] ?? '');
            $hostB = mb_strtolower($b['host_name'] ?? '');

            $probA = mb_strtolower($a['name'] ?? '');
            $probB = mb_strtolower($b['name'] ?? '');

            $idA = $a['eventid'] ?? '';
            $idB = $b['eventid'] ?? '';

            if ($sortField === 'severity') {
                if ($sevA !== $sevB) {
                    return ($sevA <=> $sevB) * $direction;
                }

                return $ageB <=> $ageA; // fallback age desc
            }

            if ($sortField === 'age') {
                if ($ageA !== $ageB) {
                    return ($ageA <=> $ageB) * $direction;
                }

                return $sevB <=> $sevA; // fallback sev desc
            }

            if ($sortField === 'host') {
                if ($hostA !== $hostB) {
                    return strcmp($hostA, $hostB) * $direction;
                }
                if ($sevA !== $sevB) {
                    return $sevB <=> $sevA;
                }

                return strcmp($idA, $idB);
            }

            if ($sortField === 'problem') {
                if ($probA !== $probB) {
                    return strcmp($probA, $probB) * $direction;
                }
                if ($sevA !== $sevB) {
                    return $sevB <=> $sevA;
                }

                return strcmp($idA, $idB);
            }

            return 0;
        });

        return [
            'problems' => $problems,
            'total_cached_count' => $totalCachedCount,
        ];
    }
}
