<?php

namespace App\Console\Commands;

use App\Services\OwnerSuggestion\OwnerSuggestionStatsRebuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RebuildOwnerSuggestionStatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'owner-suggestion:rebuild-stats';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuilds aggregate owner suggestion statistics from raw observations.';

    /**
     * Execute the console command.
     */
    public function handle(OwnerSuggestionStatsRebuilder $rebuilder): int
    {
        $this->info('Starting Owner Suggestion stats rebuild...');

        try {
            $summary = $rebuilder->rebuild();

            \Illuminate\Support\Facades\Cache::put('owner_suggestion_last_rebuild_at', now()->timestamp);

            $this->info('Rebuild completed successfully.');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Observations Scanned', $summary['observations_scanned']],
                    ['Stats Written', $summary['stats_written']],
                    ['Raw Observations Deleted', $summary['raw_deleted']],
                    ['Retention Days', $summary['retention_days']],
                    ['Old Weight Coefficient', $summary['old_weight_coefficient']],
                    ['Cleanup Days', $summary['cleanup_days']],
                ]
            );

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('Owner Suggestion stats rebuild failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Rebuild failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
