<?php

namespace App\Console\Commands;

use App\Services\ScheduledZnunyTaskRetentionService;
use Illuminate\Console\Command;

class ScheduledZnunyTaskCleanupRunsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scheduled-znuny:cleanup-runs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old scheduled Znuny task runs';

    /**
     * Execute the console command.
     */
    public function handle(ScheduledZnunyTaskRetentionService $retentionService)
    {
        $deletedCount = $retentionService->cleanupOldRuns();
        $this->info("Cleaned up {$deletedCount} old scheduled task runs.");
    }
}
