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
