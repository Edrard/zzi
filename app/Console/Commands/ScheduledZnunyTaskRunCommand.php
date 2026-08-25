<?php

namespace App\Console\Commands;

use App\Services\ScheduledZnunyTaskRunner;
use Illuminate\Console\Command;

class ScheduledZnunyTaskRunCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scheduled-znuny:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run pending scheduled Znuny tasks';

    /**
     * Execute the console command.
     */
    public function handle(ScheduledZnunyTaskRunner $runner)
    {
        $runner->run();
    }
}
