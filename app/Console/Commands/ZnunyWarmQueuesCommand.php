<?php

namespace App\Console\Commands;

use App\Enums\ZnunyPrewarmRefreshResult;
use App\Services\Znuny\Cache\PrewarmSnapshotManager;

use App\Services\Znuny\ZnunyClient;
use Illuminate\Console\Command;

class ZnunyWarmQueuesCommand extends Command
{
    protected $signature = 'znuny:cache:warm-queues';
    protected $description = 'Warm up the Znuny queues cache snapshot';

    public function handle(ZnunyClient $client)
    {
        $this->info('Starting Znuny queues cache warmup...');

        $manager = new PrewarmSnapshotManager('queues');

        $result = $manager->refresh(function () use ($client) {
            $queues = $client->getQueues();

            if (! is_array($queues) || empty($queues)) {
                throw new \Exception('Invalid payload: unexpectedly empty or invalid.');
            }

            $normalizedQueues = [];
            $seenQueueIds = [];

            foreach ($queues as $queue) {
                if (! is_array($queue) || ! isset($queue['id']) || ! isset($queue['name']) || ! isset($queue['valid_id'])) {
                    throw new \Exception('Invalid payload: malformed normalized queue entry.');
                }

                $queueName = trim((string) $queue['name']);
                if ($queueName === '') {
                    throw new \Exception('Invalid payload: empty queue name.');
                }

                if (! $this->isValidId($queue['id'])) {
                    throw new \Exception('Invalid payload: missing or invalid queue ID.');
                }

                if (! is_int($queue['valid_id'])) {
                    throw new \Exception('Invalid payload: valid_id must be an integer.');
                }

                if ($queue['valid_id'] !== 1) {
                    continue; // Skip inactive queues
                }

                $qId = (int) $queue['id'];

                if (isset($seenQueueIds[$qId])) {
                    throw new \Exception("Duplicate queue ID detected: {$qId}.");
                }
                $seenQueueIds[$qId] = true;

                $queue['id'] = $qId;
                $normalizedQueues[] = $queue;
            }

            if (empty($normalizedQueues)) {
                throw new \Exception('Invalid payload: no active queues remaining after filtering.');
            }

            usort($normalizedQueues, function ($a, $b) {
                $cmp = strcmp($a['name'], $b['name']);
                if ($cmp === 0) {
                    return $a['id'] <=> $b['id'];
                }
                return $cmp;
            });

            return [
                'payload' => $normalizedQueues,
                'item_count' => count($normalizedQueues),
            ];
        }, 'artisan', max(3, \App\Services\SettingsService::int('znuny_prewarm_queues_interval_minutes', 5)));

        if ($result === ZnunyPrewarmRefreshResult::SKIPPED_LOCKED) {
            $this->warn('Znuny queues cache warmup skipped: Another refresh is already running.');
            $this->line('PREWARM_RESULT=skipped_locked');
            return self::SUCCESS;
        }

        if ($result === ZnunyPrewarmRefreshResult::FAILED) {
            $this->error('Failed to warm queues cache. Error: ' . $this->getSafeFailureMessage($manager));
            $this->line('PREWARM_RESULT=failed');
            return self::FAILURE;
        }

        $this->info('Successfully warmed Znuny queues cache.');
        $this->line('PREWARM_RESULT=success');
        return self::SUCCESS;
    }

    private function getSafeFailureMessage(PrewarmSnapshotManager $manager): string
    {
        try {
            $meta = $manager->readMetadata();
            if (!empty($meta['last_error']) && is_string($meta['last_error'])) {
                $error = trim($meta['last_error']);
                if ($error !== '') {
                    return $error;
                }
            }
        } catch (\Throwable $e) {
            // Do not expose metadata read exception
        }

        return 'Refresh failed; see application logs.';
    }

    private function isValidId($id): bool
    {
        if (is_int($id) && $id > 0) {
            return true;
        }
        if (is_string($id) && preg_match('/^[1-9][0-9]*$/', $id)) {
            return true;
        }
        return false;
    }
}
