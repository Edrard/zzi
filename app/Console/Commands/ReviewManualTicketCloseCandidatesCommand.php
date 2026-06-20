<?php

namespace App\Console\Commands;

use App\Services\Znuny\ZnunyManualTicketCloseCandidateService;
use Illuminate\Console\Command;

class ReviewManualTicketCloseCandidatesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'znuny:review-manual-ticket-close-candidates {--ticket-id= : Review a specific local ZabbixTicket ID} {--json : Output report as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dry-run review of manual linked tickets eligible for auto-close';

    /**
     * Execute the console command.
     */
    public function handle(ZnunyManualTicketCloseCandidateService $service)
    {
        $ticketId = $this->option('ticket-id') ? (int) $this->option('ticket-id') : null;
        $asJson = $this->option('json');

        if (! $asJson) {
            $this->info('Reviewing manual ticket close candidates (DRY RUN)...');
        }

        $report = $service->review($ticketId);

        if ($asJson) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if (empty($report['candidates'])) {
            $this->info('No close candidates found.');
        } else {
            $this->table(
                ['Ticket Number', 'Host', 'Problem', 'Znuny State', 'Lifecycle Status', 'Resolved Since', 'Close Eligible At', 'Flap Count', 'Reason'],
                array_map(function ($c) {
                    return [
                        $c['ticket_number'],
                        $c['host'],
                        $c['problem'],
                        $c['znuny_state'],
                        $c['lifecycle_status'],
                        $c['resolved_since'] ?? '-',
                        $c['close_eligible_at'] ?? '-',
                        $c['flap_count'],
                        $c['reason'],
                    ];
                }, $report['candidates'])
            );
        }

        $this->info('Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Scanned', $report['summary']['scanned']],
                ['Candidates', $report['summary']['candidates']],
                ['Skipped: Closed', $report['summary']['skipped_closed']],
                ['Skipped: Not Manual', $report['summary']['skipped_not_manual']],
                ['Skipped: Not Candidate', $report['summary']['skipped_not_candidate']],
                ['Skipped: Cache Stale', $report['summary']['skipped_cache_stale']],
                ['Skipped: Auto Close Disabled', $report['summary']['skipped_auto_close_disabled']],
                ['Skipped: Future Eligibility', $report['summary']['skipped_future_eligibility']],
            ]
        );

        return self::SUCCESS;
    }
}
