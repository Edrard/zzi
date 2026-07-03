<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

#[Signature('app:cleanup')]
#[Description('Cleanup old records')]
class Cleanup extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        Log::info('Started app:cleanup');

        if (! SettingsService::cleanupEnabled()) {
            $this->info('Cleanup is disabled.');

            return self::SUCCESS;
        }

        $this->info('Cleanup started.');

        $batchSize = SettingsService::cleanupBatchSize();
        $auditRetentionDays = SettingsService::retentionActionLogsDays();
        $failedJobsRetentionDays = SettingsService::retentionFailedJobsDays();

        $auditDeletedCount = 0;

        $failedJobsDeletedCount = 0;

        try {
            // Cleanup Audit Logs
            $auditDateLimit = now()->subDays($auditRetentionDays);
            do {
                $deleted = DB::table('audit_logs')
                    ->where('created_at', '<', $auditDateLimit)
                    ->limit($batchSize)
                    ->delete();
                $auditDeletedCount += $deleted;
            } while ($deleted === $batchSize);

            // Cleanup Failed Jobs
            if (Schema::hasTable('failed_jobs')) {
                $timestampColumn = null;
                if (Schema::hasColumn('failed_jobs', 'failed_at')) {
                    $timestampColumn = 'failed_at';
                } elseif (Schema::hasColumn('failed_jobs', 'created_at')) {
                    $timestampColumn = 'created_at';
                }

                if ($timestampColumn) {
                    $jobsDateLimit = now()->subDays($failedJobsRetentionDays);
                    do {
                        $deleted = DB::table('failed_jobs')
                            ->where($timestampColumn, '<', $jobsDateLimit)
                            ->limit($batchSize)
                            ->delete();
                        $failedJobsDeletedCount += $deleted;
                    } while ($deleted === $batchSize);
                }
            }

            AuditLogger::log(
                action: 'cleanup.finished',
                entityType: 'cleanup',
                entityId: null,
                context: [
                    'audit_logs_deleted' => $auditDeletedCount,

                    'failed_jobs_deleted' => $failedJobsDeletedCount,
                    'cleanup_batch_size' => $batchSize,
                    'retention_action_logs_days' => $auditRetentionDays,
                    'retention_failed_jobs_days' => $failedJobsRetentionDays,

                ]
            );

            $this->info("Deleted {$auditDeletedCount} audit_logs.");

            $this->info("Deleted {$failedJobsDeletedCount} failed_jobs.");
            $this->info('Cleanup finished.');

            return self::SUCCESS;

        } catch (\Throwable $e) {
            AuditLogger::log(
                action: 'cleanup.failed',
                entityType: 'cleanup',
                entityId: null,
                context: [
                    'error' => $e->getMessage(),
                ]
            );

            $this->error('Cleanup failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
