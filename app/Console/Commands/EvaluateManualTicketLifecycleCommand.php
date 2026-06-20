<?php

namespace App\Console\Commands;

use App\Services\Znuny\ZnunyManualTicketLifecycleService;
use Illuminate\Console\Command;

class EvaluateManualTicketLifecycleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'znuny:evaluate-manual-ticket-lifecycle {--ticket-id= : Evaluate a specific local ZabbixTicket ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Evaluates and updates local lifecycle states for manual Znuny tickets';

    /**
     * Execute the console command.
     */
    public function handle(ZnunyManualTicketLifecycleService $service)
    {
        $this->info('Evaluating manual ticket lifecycle...');

        $ticketId = $this->option('ticket-id') ? (int) $this->option('ticket-id') : null;

        $stats = $service->evaluate($ticketId);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Scanned', $stats['scanned']],
                ['Active', $stats['active']],
                ['Resolved Waiting', $stats['resolved_waiting']],
                ['Close Candidate', $stats['close_candidate']],
                ['Flapping', $stats['flapping']],
                ['Closed', $stats['closed']],
                ['Skipped', $stats['skipped']],
                ['Failed', $stats['failed']],
            ]
        );

        $this->info('Lifecycle evaluation complete.');

        return self::SUCCESS;
    }
}
