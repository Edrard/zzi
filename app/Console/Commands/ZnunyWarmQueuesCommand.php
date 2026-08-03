<?php

namespace App\Console\Commands;

use App\Services\Znuny\Cache\PrewarmSnapshotManager;
use App\Services\Znuny\ZnunyClient;
use Illuminate\Console\Command;

class ZnunyWarmQueuesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'znuny:cache:warm-queues';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warm up the Znuny queues cache snapshot';

    /**
     * Execute the console command.
     */
    public function handle(ZnunyClient $client)
    {
        $this->info('Starting Znuny queues cache warmup...');

        $manager = new PrewarmSnapshotManager('queues');

        $success = $manager->refresh(function () use ($client) {
            $queues = $client->getQueues();
            
            if (! is_array($queues) || empty($queues)) {
                throw new \Exception('Invalid payload: unexpectedly empty or invalid.');
            }

            foreach ($queues as $queue) {
                if (! is_array($queue) || empty($queue['id']) || empty($queue['name']) || ! isset($queue['valid_id'])) {
                    throw new \Exception('Invalid payload: malformed normalized queue entry.');
                }
            }

            // Deterministic sorting by name
            usort($queues, function ($a, $b) {
                return strcmp($a['name'], $b['name']);
            });

            return $queues;
        }, 'artisan');

        if (! $success) {
            $meta = $manager->readMetadata();
            $this->error('Failed to warm queues cache. Error: ' . ($meta['last_error'] ?? 'Unknown error'));
            return self::FAILURE;
        }

        $this->info('Successfully warmed Znuny queues cache.');
        return self::SUCCESS;
    }
}
