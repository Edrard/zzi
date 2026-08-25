<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\Znuny\ZnunyClient;
use Illuminate\Console\Command;

class TestZnunyConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-znuny {--ticket-id=55992 : Znuny internal TicketID used for TicketGet verification}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the Znuny 6.2 REST API connection and configuration';

    /**
     * Execute the console command.
     */
    public function handle(ZnunyClient $client): int
    {
        $this->info('Testing Znuny API connection...');

        $ticketId = $this->option('ticket-id');
        $result = $client->testConnection($ticketId);

        if ($result['status'] === 'success') {
            $this->info('Connection status: SUCCESS');
            $this->line("Ticket ID: {$result['TicketID']}");
            $this->line("Ticket number: {$result['TicketNumber']}");
            $this->line("Title: {$result['Title']}");
            $this->line("Queue: {$result['Queue']}");
            $this->line("Owner: {$result['Owner']}");
            $this->line("State: {$result['State']}");
            $this->line("Ticket URL: {$result['ticket_url']}");

            AuditLogger::log(
                action: 'znuny.connection_tested',
                entityType: 'system',
                entityId: null,
                context: [
                    'status' => 'success',
                    'ticket_id' => $result['TicketID'],
                    'ticket_number' => $result['TicketNumber'],
                ]
            );

            return 0;
        }

        $this->error('Connection status: FAILED');
        $this->error("Error: {$result['error']}");

        AuditLogger::log(
            action: 'znuny.connection_failed',
            entityType: 'system',
            entityId: null,
            context: [
                'status' => 'failed',
                'error' => $result['error'],
            ]
        );

        return 1;
    }
}
