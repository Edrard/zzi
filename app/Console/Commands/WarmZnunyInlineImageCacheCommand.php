<?php

namespace App\Console\Commands;

use App\Services\SettingsService;
use App\Services\Znuny\ZnunyInlineImageWarmerService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class WarmZnunyInlineImageCacheCommand extends Command
{
    protected $signature = 'znuny:warm-inline-image-cache {--scheduled : Indicates if the command is run from the scheduler}';

    protected $description = 'Warm up the local inline image cache for active tickets';

    public function handle(ZnunyInlineImageWarmerService $warmerService): int
    {
        $isScheduled = $this->option('scheduled');

        if (! SettingsService::bool('znuny_inline_image_warmer_enabled', false)) {
            $this->info('Inline image warmer is disabled in settings. Exiting cleanly.');

            return self::SUCCESS;
        }

        if ($isScheduled) {
            $interval = max(1, min(1440, SettingsService::int('znuny_inline_image_warmer_interval_minutes', 5) ?? 5));
            $lastRunAt = Redis::get('znuny:inline_image_warmer:last_run_at');

            if ($lastRunAt) {
                $dueAt = Carbon::createFromTimestamp($lastRunAt)->addMinutes($interval);
                if (Carbon::now()->lessThan($dueAt)) {
                    $this->info('Scheduled warmer is not due yet. Exiting cleanly.');

                    return self::SUCCESS;
                }
            }
        }

        $this->info('Starting Znuny inline image cache warmer...');

        $result = $warmerService->warm();

        if ($result['status'] !== 'success') {
            $this->info("Warmer skipped/aborted: {$result['status']}");

            return self::SUCCESS; // Handled skips
        }

        $this->info('Completed warmer cycle:');
        $this->info("- Total active tickets: {$result['total_active']}");
        $this->info("- Hot slots max: {$result['hot_slots']}");
        $this->info("- Tail slots max: {$result['tail_slots']}");
        $this->info("- Selected unique tickets: {$result['selected_unique_tickets']}");
        $this->info("- Inline references discovered: {$result['references_discovered']}");
        $this->info("- Inline references processed: {$result['references_processed']}");
        $this->info("- Errors encountered: {$result['errors']}");

        return self::SUCCESS;
    }
}
