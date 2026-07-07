<?php

namespace App\Services;

use App\Models\ScheduledZnunyTaskRun;
use App\Models\SystemAlert;
use Illuminate\Support\Facades\Log;

class ScheduledZnunyTaskRunProcessor
{
    public function __construct(private SystemAlertService $alertService) {}

    public function processNextBatch(int $limit, int $maxRuntimeSeconds): int
    {
        $startTime = microtime(true);
        $processedCount = 0;

        $runs = ScheduledZnunyTaskRun::where('status', 'pending')
            ->orderBy('scheduled_for', 'asc')
            ->orderBy('id', 'asc')
            ->take($limit)
            ->get();

        foreach ($runs as $run) {
            // Check runtime limit
            if ((microtime(true) - $startTime) >= $maxRuntimeSeconds) {
                break;
            }

            $this->processRun($run);
            $processedCount++;
        }

        return $processedCount;
    }

    private function processRun(ScheduledZnunyTaskRun $run): void
    {
        $startedAt = now();
        $run->update([
            'status' => 'running',
            'started_at' => $startedAt,
        ]);

        try {
            $finishedAt = now();
            // Phase 2 processed rows must end as skipped
            $run->update([
                'status' => 'skipped',
                'finished_at' => $finishedAt,
                'duration_ms' => $finishedAt->diffInMilliseconds($startedAt),
                'error_summary' => 'Ticket creation is not implemented until Phase 3',
                'error_details' => 'Ticket creation is not implemented until Phase 3',
            ]);

            if ($run->task) {
                $run->task->update([
                    'last_run_at' => $finishedAt,
                    'last_status' => 'skipped',
                ]);
            }
        } catch (\Throwable $e) {
            $finishedAt = now();
            Log::error('Scheduled task run failed: '.$e->getMessage(), ['run_id' => $run->id]);

            $run->update([
                'status' => 'failed',
                'finished_at' => $finishedAt,
                'duration_ms' => $finishedAt->diffInMilliseconds($startedAt),
                'error_summary' => substr($e->getMessage(), 0, 255),
                'error_details' => $e->getMessage()."\n".$e->getTraceAsString(),
            ]);

            if ($run->task) {
                $run->task->update([
                    'last_run_at' => $finishedAt,
                    'last_status' => 'failed',
                    'last_failure_at' => $finishedAt,
                ]);
            }

            $this->alertService->danger(
                'scheduler',
                'Scheduled Task Failed',
                "Task '{$run->task_name_snapshot}' failed. Error: {$e->getMessage()}"
            );
        }
    }
}
