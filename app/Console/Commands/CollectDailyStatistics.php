<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('app:collect-daily-statistics')]
#[Description('Collect daily statistics')]
class CollectDailyStatistics extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        Log::info('Started app:collect-daily-statistics');
        $this->info('Collecting daily statistics... (stub)');

        return self::SUCCESS;
    }
}
