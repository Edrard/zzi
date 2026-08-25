<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\Zabbix\ZabbixClient;
use Exception;
use Illuminate\Console\Command;

class TestZabbixConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-zabbix';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Zabbix API connection and authentication';

    /**
     * Execute the console command.
     */
    public function handle(ZabbixClient $client): int
    {
        $this->info('Testing Zabbix API connection...');

        try {
            $testResult = $client->testConnection();

            $this->info('Connection status: '.$testResult['status']);
            $this->info('Zabbix API version: '.$testResult['version']);

            $this->info('Fetching problems limit 5...');
            $problems = $client->getProblems(['limit' => 5]);

            $this->info('Number of problems returned: '.count($problems));

            if (count($problems) > 0) {
                $first = $problems[0];
                $this->info('First problem:');
                $this->info('- Event ID: '.($first['eventid'] ?? 'N/A'));
                $this->info('- Name: '.($first['name'] ?? 'N/A'));
                $this->info('- Severity: '.($first['severity'] ?? 'N/A'));
            }

            AuditLogger::log(
                action: 'zabbix.connection_tested',
                entityType: 'system',
                entityId: null,
                context: [
                    'status' => 'success',
                    'version' => $testResult['version'],
                ]
            );

            $this->info('Test completed successfully.');

        } catch (Exception $e) {
            $this->error('Connection failed: '.$e->getMessage());

            AuditLogger::log(
                action: 'zabbix.connection_failed',
                entityType: 'system',
                entityId: null,
                context: [
                    'error' => $e->getMessage(),
                ]
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
