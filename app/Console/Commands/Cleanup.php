<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
        $this->info('Cleaning up old records... (stub)');

        return self::SUCCESS;
    }
}
