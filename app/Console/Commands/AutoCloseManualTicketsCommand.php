<?php

namespace App\Console\Commands;

use App\Models\ZabbixTicket;
use App\Services\AuditLogger;
use App\Services\Znuny\ZnunyManualTicketAutoCloseService;
use App\Services\Znuny\ZnunyManualTicketCloseCandidateService;
use Illuminate\Console\Command;

class AutoCloseManualTicketsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'znuny:auto-close-manual-tickets
                            {--execute : Perform actual close in Znuny}
                            {--ticket-id= : Focus on a specific ticket ID}
                            {--limit= : Limit the number of tickets to process}
                            {--json : Output result as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically close eligible manual tickets in Znuny (default is dry-run)';

    /**
     * Execute the console command.
     */
    public function handle(
        ZnunyManualTicketCloseCandidateService $candidateService,
        ZnunyManualTicketAutoCloseService $autoCloseService
    ) {
        $execute = $this->option('execute');
        $ticketId = $this->option('ticket-id') ? (int) $this->option('ticket-id') : null;
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $json = $this->option('json');

        // Initial dry-run scan to find candidates
        $report = $candidateService->review($ticketId);
        $candidates = $report['candidates'] ?? [];

        if ($limit && count($candidates) > $limit) {
            $candidates = array_slice($candidates, 0, $limit);
        }

        $summary = [
            'scanned' => $report['summary']['scanned'] ?? 0,
            'candidates' => count($candidates),
            'would_close' => 0,
            'closed' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $results = [];

        if (! $execute) {
            // DRY RUN MODE
            $summary['would_close'] = count($candidates);

            foreach ($candidates as $c) {
                $results[] = array_merge($c, [
                    'action' => 'dry-run',
                    'result' => 'Would close (dry-run)',
                ]);
            }

            if (count($candidates) > 0) {
                AuditLogger::log(
                    'znuny.auto_close.dry_run',
                    'system',
                    null,
                    [
                        'summary' => $summary,
                        'candidates' => array_map(fn ($r) => [
                            'ticket_number' => $r['ticket_number'],
                            'reason' => $r['reason'],
                        ], $candidates),
                    ]
                );
            }

            if ($json) {
                $this->line(json_encode([
                    'mode' => 'dry-run',
                    'summary' => $summary,
                    'results' => $results,
                ]));

                return Command::SUCCESS;
            }

            $this->info('Auto-closing manual tickets (DRY RUN)...');
            if (empty($results)) {
                $this->line('No candidates found to close.');
            } else {
                $this->table(
                    ['Ticket Number', 'Host', 'Problem', 'Znuny State', 'Action', 'Result'],
                    array_map(fn ($r) => [
                        $r['ticket_number'],
                        $r['host'],
                        $r['problem'],
                        $r['znuny_state'],
                        $r['action'],
                        $r['result'],
                    ], $results)
                );
            }

            $this->line('Summary:');
            $this->table(
                ['Metric', 'Count'],
                array_map(fn ($k, $v) => [ucwords(str_replace('_', ' ', $k)), $v], array_keys($summary), $summary)
            );

            return Command::SUCCESS;
        }

        // EXECUTE MODE
        foreach ($candidates as $c) {
            $ticket = ZabbixTicket::find($c['id']);
            if (! $ticket) {
                continue;
            }

            $outcome = $autoCloseService->executeClose($ticket);

            $resultMessage = $outcome['reason'];
            if (! empty($outcome['warning'])) {
                $resultMessage .= ' (Warning: '.$outcome['warning'].')';
            }

            $results[] = array_merge($c, [
                'action' => 'execute',
                'result' => $resultMessage,
            ]);

            if ($outcome['success']) {
                $summary['closed']++;

                $logContext = [
                    'ticket_number' => $ticket->znuny_ticket_number,
                    'host' => $ticket->zabbix_host_name,
                    'problem' => $ticket->zabbix_problem_name,
                ];
                if (! empty($outcome['warning'])) {
                    $logContext['warning'] = $outcome['warning'];
                }

                AuditLogger::log(
                    'znuny.auto_close.success',
                    'zabbix_ticket',
                    $ticket->id,
                    $logContext
                );
            } elseif ($outcome['skipped']) {
                $summary['skipped']++;
            } else {
                $summary['failed']++;
                AuditLogger::log(
                    'znuny.auto_close.failed',
                    'zabbix_ticket',
                    $ticket->id,
                    [
                        'ticket_number' => $ticket->znuny_ticket_number,
                        'host' => $ticket->zabbix_host_name,
                        'problem' => $ticket->zabbix_problem_name,
                        'reason' => $outcome['reason'],
                    ]
                );
            }
        }

        if ($json) {
            $this->line(json_encode([
                'mode' => 'execute',
                'summary' => $summary,
                'results' => $results,
            ]));

            return Command::SUCCESS;
        }

        $this->warn('Auto-closing manual tickets (EXECUTE MODE)...');

        if (empty($results)) {
            $this->line('No candidates found to close.');
        } else {
            $this->table(
                ['Ticket Number', 'Host', 'Problem', 'Znuny State', 'Action', 'Result'],
                array_map(fn ($r) => [
                    $r['ticket_number'],
                    $r['host'],
                    $r['problem'],
                    $r['znuny_state'],
                    $r['action'],
                    $r['result'],
                ], $results)
            );
        }

        $this->line('Summary:');
        $this->table(
            ['Metric', 'Count'],
            array_map(fn ($k, $v) => [ucwords(str_replace('_', ' ', $k)), $v], array_keys($summary), $summary)
        );

        return Command::SUCCESS;
    }
}
