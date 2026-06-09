<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('app:poll-problems')]
#[Description('Poll Zabbix for new problems')]
class PollProblems extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        Log::info('Started app:poll-problems');
        $this->info('Polling Zabbix problems... (stub)');

        return self::SUCCESS;
    }
}
