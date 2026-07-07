<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ScheduledZnunyTaskRunner
{
    public function __construct(
        private ScheduledZnunyTaskQueueService $queueService,
        private ScheduledZnunyTaskRunProcessor $processor,
        private SchedulerSafetyService $safetyService
    ) {}

    public function run(): void
    {
        $maxRuntime = SettingsService::int('scheduled_tasks_command_runtime_seconds', 50);
        $lockTtl = $maxRuntime + 10;

        $lock = Cache::lock('scheduled_znuny_task_runner', $lockTtl);

        if (! $lock->get()) {
            return;
        }

        try {
            // 1. Materialize all pending runs to ensure we don't skip executions
            try {
                $this->queueService->materializePendingRuns();
            } catch (\Exception $e) {
                Log::error('Scheduler failed to materialize runs: '.$e->getMessage());
                // We can continue to process existing pending runs
            }

            if (! $this->safetyService->isSchedulerEnabled()) {
                return; // Globally disabled
            }

            // 2. Check if we are paused, skip processing if so
            if ($this->safetyService->isSchedulerPaused()) {
                return;
            }

            // 3. Process pending runs
            $limit = SettingsService::int('scheduled_tasks_max_processed_per_run', 5);

            try {
                $this->processor->processNextBatch($limit, $maxRuntime);
            } catch (\Exception $e) {
                Log::error('Scheduler failed to process runs: '.$e->getMessage());
            }
        } finally {
            $lock->release();
        }
    }
}
