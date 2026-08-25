<?php

namespace App\Services\OwnerSuggestion;

use App\Models\ZnunyOwnerSuggestionObservation;
use App\Models\ZnunyOwnerSuggestionStat;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OwnerSuggestionStatsRebuilder
{
    public function rebuild(): array
    {
        $retentionDays = SettingsService::int('owner_suggestion_statistics_retention_days', 70);
        $oldWeight = (float) SettingsService::get('owner_suggestion_old_weight_coefficient', 0.5);
        $cleanupDays = SettingsService::int('owner_suggestion_observation_cleanup_days', 360);

        $retentionDate = Carbon::now()->subDays($retentionDays);
        $cleanupDate = Carbon::now()->subDays($cleanupDays);

        return DB::transaction(function () use ($cleanupDate, $retentionDate, $oldWeight, $retentionDays, $cleanupDays) {
            // Delete old raw observations
            $rawDeleted = ZnunyOwnerSuggestionObservation::where('created_at', '<', $cleanupDate)->delete();

            // Clear existing stats
            ZnunyOwnerSuggestionStat::query()->delete();

            // Use a database-safe query to group and calculate metrics
            $query = ZnunyOwnerSuggestionObservation::query()
                ->where(function ($q) {
                    $q->whereNotNull('owner_id')
                        ->orWhere(function ($q2) {
                            $q2->whereNotNull('owner_login')
                                ->where('owner_login', '!=', '');
                        });
                })
                ->selectRaw('
                    normalized_problem_key,
                    queue_name,
                    owner_id,
                    CASE WHEN owner_id IS NULL THEN owner_login ELSE NULL END as group_owner_login,
                    COUNT(id) as sample_count,
                    SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as recent_count,
                    SUM(CASE WHEN created_at < ? THEN 1 ELSE 0 END) as old_count,
                    MAX(created_at) as last_seen_at
                ', [$retentionDate, $retentionDate])
                ->groupByRaw('normalized_problem_key, queue_name, owner_id, CASE WHEN owner_id IS NULL THEN owner_login ELSE NULL END');

            $statsWritten = 0;
            $observationsScanned = 0;
            $now = Carbon::now();

            foreach ($query->cursor() as $row) {
                $sampleCount = (int) $row->sample_count;
                $recentCount = (int) $row->recent_count;
                $oldCount = (int) $row->old_count;

                $observationsScanned += $sampleCount;
                $score = $recentCount + ($oldCount * $oldWeight);

                $latestOwnerLogin = $this->resolveLatestOwnerLogin(
                    $row->normalized_problem_key,
                    $row->queue_name,
                    $row->owner_id,
                    $row->group_owner_login
                );

                ZnunyOwnerSuggestionStat::create([
                    'normalized_problem_key' => $row->normalized_problem_key,
                    'queue_name' => $row->queue_name,
                    'owner_id' => $row->owner_id,
                    'owner_login' => $latestOwnerLogin,
                    'sample_count' => $sampleCount,
                    'recent_count' => $recentCount,
                    'old_count' => $oldCount,
                    'score' => $score,
                    'last_seen_at' => $row->last_seen_at,
                    'calculated_at' => $now,
                ]);

                $statsWritten++;
            }

            return [
                'observations_scanned' => $observationsScanned,
                'stats_written' => $statsWritten,
                'raw_deleted' => $rawDeleted,
                'retention_days' => $retentionDays,
                'old_weight_coefficient' => $oldWeight,
                'cleanup_days' => $cleanupDays,
            ];
        });
    }

    private function resolveLatestOwnerLogin(string $normalizedKey, ?string $queue, int|string|null $ownerId, ?string $groupOwnerLogin): ?string
    {
        if ($ownerId === null) {
            return $groupOwnerLogin;
        }

        $latestLogin = ZnunyOwnerSuggestionObservation::query()
            ->where('normalized_problem_key', $normalizedKey)
            ->where('queue_name', $queue)
            ->where('owner_id', $ownerId)
            ->whereNotNull('owner_login')
            ->where('owner_login', '!=', '')
            ->latest('created_at')
            ->value('owner_login');

        return $latestLogin ?: null;
    }
}
