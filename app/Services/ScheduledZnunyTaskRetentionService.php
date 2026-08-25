<?php

namespace App\Services;

use App\Models\ScheduledZnunyTaskRun;
use Illuminate\Support\Facades\Log;

class ScheduledZnunyTaskRetentionService
{
    public function cleanupOldRuns(): int
    {
        $retentionDays = SettingsService::int('scheduled_task_logs_retention_days', 180);
        $cutoffDate = now()->subDays($retentionDays)->toDateTimeString();

        $deletedCount = ScheduledZnunyTaskRun::where('created_at', '<', $cutoffDate)->delete();

        Log::info("Cleaned up {$deletedCount} old scheduled task runs older than {$retentionDays} days (cutoff: {$cutoffDate}).");

        return $deletedCount;
    }
}
