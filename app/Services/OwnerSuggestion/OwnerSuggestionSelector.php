<?php

namespace App\Services\OwnerSuggestion;

use App\Models\ZnunyOwnerSuggestionStat;

class OwnerSuggestionSelector
{
    public function __construct(
        private ProblemNameNormalizer $normalizer,
        private ProblemSimilarityService $similarityService
    ) {}

    public function rank(
        string $problemName,
        ?string $queueName = null,
        array $allowedOwnerIds = [],
        array $allowedOwnerLogins = []
    ): array {
        if (trim($problemName) === '') {
            return [];
        }

        $normalizedKey = $this->normalizer->normalize($problemName);
        if (trim($normalizedKey) === '') {
            return [];
        }

        $query = ZnunyOwnerSuggestionStat::query()
            ->where(function ($q) {
                $q->whereNotNull('owner_id')
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('owner_login')
                            ->where('owner_login', '!=', '');
                    });
            });

        if (! empty($allowedOwnerIds) || ! empty($allowedOwnerLogins)) {
            $query->where(function ($q) use ($allowedOwnerIds, $allowedOwnerLogins) {
                if (! empty($allowedOwnerIds)) {
                    $q->whereIn('owner_id', $allowedOwnerIds);
                }
                if (! empty($allowedOwnerLogins)) {
                    $q->orWhereIn('owner_login', $allowedOwnerLogins);
                }
            });
        }

        $candidates = [];

        foreach ($query->cursor() as $stat) {
            if (! $this->similarityService->isSimilar($normalizedKey, $stat->normalized_problem_key)) {
                continue;
            }

            $similarity = $this->similarityService->similarity($normalizedKey, $stat->normalized_problem_key);
            $isQueueMatch = $queueName !== null && strcasecmp((string) $stat->queue_name, $queueName) === 0;

            $candidates[] = [
                'stat' => $stat,
                'similarity' => $similarity,
                'is_queue_match' => $isQueueMatch,
            ];
        }

        if (empty($candidates)) {
            return [];
        }

        usort($candidates, function (array $a, array $b) {
            // 1. Queue match first
            if ($a['is_queue_match'] !== $b['is_queue_match']) {
                return $a['is_queue_match'] ? -1 : 1;
            }

            // 2. Higher similarity score first
            $simDiff = $b['similarity'] <=> $a['similarity'];
            if ($simDiff !== 0) {
                return $simDiff;
            }

            // 3. Higher aggregate score
            $scoreDiff = $b['stat']->score <=> $a['stat']->score;
            if ($scoreDiff !== 0) {
                return $scoreDiff;
            }

            // 4. Higher recent_count
            $recentDiff = $b['stat']->recent_count <=> $a['stat']->recent_count;
            if ($recentDiff !== 0) {
                return $recentDiff;
            }

            // 5. Higher sample_count
            $sampleDiff = $b['stat']->sample_count <=> $a['stat']->sample_count;
            if ($sampleDiff !== 0) {
                return $sampleDiff;
            }

            // 6. Latest last_seen_at
            if ($b['stat']->last_seen_at && $a['stat']->last_seen_at) {
                $timeDiff = $b['stat']->last_seen_at->timestamp <=> $a['stat']->last_seen_at->timestamp;
                if ($timeDiff !== 0) {
                    return $timeDiff;
                }
            }

            return 0;
        });

        $results = [];
        foreach ($candidates as $best) {
            $results[] = [
                'owner_id' => $best['stat']->owner_id,
                'owner_login' => $best['stat']->owner_login,
                'queue_name' => $best['stat']->queue_name,
                'normalized_problem_key' => $normalizedKey,
                'matched_normalized_problem_key' => $best['stat']->normalized_problem_key,
                'similarity' => $best['similarity'],
                'score' => (float) $best['stat']->score,
                'sample_count' => (int) $best['stat']->sample_count,
                'recent_count' => (int) $best['stat']->recent_count,
                'old_count' => (int) $best['stat']->old_count,
                'last_seen_at' => $best['stat']->last_seen_at,
            ];
        }

        return $results;
    }

    public function suggest(
        string $problemName,
        ?string $queueName = null,
        array $allowedOwnerIds = [],
        array $allowedOwnerLogins = []
    ): ?array {
        $ranked = $this->rank($problemName, $queueName, $allowedOwnerIds, $allowedOwnerLogins);

        return $ranked[0] ?? null;
    }
}
