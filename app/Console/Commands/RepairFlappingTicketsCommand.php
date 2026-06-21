<?php

namespace App\Console\Commands;

use App\Models\ZabbixTicket;
use Illuminate\Console\Command;

class RepairFlappingTicketsCommand extends Command
{
    protected $signature = 'znuny:repair-flapping-tickets {--dry-run : Only list affected tickets without modifying them}';

    protected $description = 'Repair manually created tickets that were incorrectly marked as flapping due to repeated evaluations without state transition.';

    public function handle()
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info('DRY RUN: Identifying polluted flapping tickets...');
        } else {
            $this->info('Repairing polluted flapping tickets...');
        }

        // Tickets that are currently flapping and active
        $tickets = ZabbixTicket::where('creation_source', 'manual')
            ->where('manual_lifecycle_status', 'flapping')
            ->where('zabbix_problem_is_active', true)
            ->get();

        $count = 0;
        foreach ($tickets as $ticket) {
            $this->info(($isDryRun ? 'Would repair' : 'Repairing')." Ticket ID {$ticket->id} (Host: {$ticket->zabbix_host_name}, Flap Count: {$ticket->manual_flap_count})");

            if (! $isDryRun) {
                $ticket->manual_lifecycle_status = 'active';
                $ticket->manual_flapping_detected_at = null;
                $ticket->manual_flap_count = 0; // Reset polluted counter
                $ticket->zabbix_problem_resolved_at = null;
                $ticket->manual_close_eligible_at = null;
                $ticket->save();
            }
            $count++;
        }

        if ($isDryRun) {
            $this->info("Dry run complete. {$count} tickets would be repaired.");
        } else {
            $this->info("Repaired {$count} tickets.");
        }

        return self::SUCCESS;
    }
}
