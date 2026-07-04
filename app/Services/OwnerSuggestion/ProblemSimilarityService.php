<?php

namespace App\Services\OwnerSuggestion;

use App\Services\SettingsService;

class ProblemSimilarityService
{
    public function tokenSimilarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        if (empty($a) || empty($b)) {
            return 0.0;
        }

        $tokensA = array_unique(preg_split('/\s+/', $a, -1, PREG_SPLIT_NO_EMPTY));
        $tokensB = array_unique(preg_split('/\s+/', $b, -1, PREG_SPLIT_NO_EMPTY));

        if (empty($tokensA) && empty($tokensB)) {
            return 1.0;
        }

        if (empty($tokensA) || empty($tokensB)) {
            return 0.0;
        }

        $common = array_intersect($tokensA, $tokensB);

        return 2.0 * count($common) / (count($tokensA) + count($tokensB));
    }

    public function trigramSimilarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        if (empty($a) || empty($b)) {
            return 0.0;
        }

        $trigramsA = $this->getTrigrams($a);
        $trigramsB = $this->getTrigrams($b);

        if (empty($trigramsA) && empty($trigramsB)) {
            return 1.0;
        }

        if (empty($trigramsA) || empty($trigramsB)) {
            return $this->tokenSimilarity($a, $b);
        }

        $uniqueA = array_unique($trigramsA);
        $uniqueB = array_unique($trigramsB);

        $common = array_intersect($uniqueA, $uniqueB);

        return 2.0 * count($common) / (count($uniqueA) + count($uniqueB));
    }

    public function similarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        if (empty($a) || empty($b)) {
            return 0.0;
        }

        $tokenSim = $this->tokenSimilarity($a, $b);
        $trigramSim = $this->trigramSimilarity($a, $b);

        return max($tokenSim, (0.70 * $tokenSim) + (0.30 * $trigramSim));
    }

    public function isSimilar(string $a, string $b, ?int $thresholdPercent = null): bool
    {
        if ($thresholdPercent === null) {
            $thresholdPercent = SettingsService::int('owner_suggestion_similarity_threshold', 80);
        }

        $threshold = $thresholdPercent / 100.0;

        return $this->similarity($a, $b) >= $threshold;
    }

    private function getTrigrams(string $str): array
    {
        $len = mb_strlen($str);
        if ($len < 3) {
            return [];
        }

        $trigrams = [];
        for ($i = 0; $i <= $len - 3; $i++) {
            $trigrams[] = mb_substr($str, $i, 3);
        }

        return $trigrams;
    }
}
