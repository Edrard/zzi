<?php

namespace App\Console\Commands;

use App\Enums\ZnunyPrewarmRefreshResult;
use App\Services\Znuny\Cache\PrewarmSnapshotManager;
use App\Services\Znuny\Cache\ZnunyQueueCacheReadService;

use App\Services\Znuny\ZnunyClient;
use Illuminate\Console\Command;

class ZnunyWarmAgentsCommand extends Command
{
    protected $signature = 'znuny:cache:warm-agents';
    protected $description = 'Warm up the Znuny active agents and assignment matrix cache.';

    public function handle(ZnunyClient $client, ZnunyQueueCacheReadService $queueService): int
    {
        $this->info('Starting Znuny agents cache warmup...');

        $manager = new PrewarmSnapshotManager('agents');

        $result = $manager->refresh(function () use ($client, $queueService) {
            $queueSnapshot = $queueService->getSnapshot();
            if (! $queueSnapshot || ! isset($queueSnapshot['payload']) || ! is_array($queueSnapshot['payload']) || empty($queueSnapshot['payload'])) {
                throw new \Exception('Prewarmed queue snapshot is missing or empty.');
            }
            if (! isset($queueSnapshot['generation']) || ! is_string($queueSnapshot['generation'])) {
                throw new \Exception('Prewarmed queue snapshot generation is missing or not a string.');
            }

            $queueGeneration = trim($queueSnapshot['generation']);
            if ($queueGeneration === '') {
                throw new \Exception('Prewarmed queue snapshot generation is blank.');
            }

            $queues = $queueSnapshot['payload'];

            $validQueueIds = [];
            foreach ($queues as $q) {
                if (! is_array($q) || ! isset($q['id']) || ! $this->isValidId($q['id'])) {
                    throw new \Exception('Queue snapshot contains malformed queue ID.');
                }
                $qId = (int) $q['id'];
                if (isset($validQueueIds[$qId])) {
                    throw new \Exception('Queue snapshot contains duplicate queue IDs.');
                }
                $validQueueIds[$qId] = true;
            }

            $rawAgents = $client->getAgents();

            if (! is_array($rawAgents) || empty($rawAgents)) {
                throw new \Exception('Invalid payload: unexpectedly empty or invalid agents.');
            }

            $agents = [];
            $agentToQueues = [];
            $queueToAgents = [];

            foreach ($validQueueIds as $qId => $true) {
                $queueToAgents[$qId] = [];
            }

            $seenAgentIds = [];

            foreach ($rawAgents as $agent) {
                if (! is_array($agent) || ! isset($agent['id']) || ! isset($agent['login']) || ! isset($agent['label'])) {
                    throw new \Exception('Invalid payload: malformed normalized agent entry.');
                }

                $login = trim((string) $agent['login']);
                $label = trim((string) $agent['label']);
                if ($login === '' || $label === '') {
                    throw new \Exception('Invalid payload: missing agent login or label.');
                }

                if (! $this->isValidId($agent['id'])) {
                    throw new \Exception('Invalid payload: missing or invalid agent ID.');
                }

                $agentId = (int) $agent['id'];

                if (isset($seenAgentIds[$agentId])) {
                    throw new \Exception('Duplicate agent ID detected.');
                }
                $seenAgentIds[$agentId] = true;

                $agent['id'] = $agentId;
                $agents[] = $agent;

                $assignableQueues = $client->getAgentAssignableQueues($agentId);

                if (! is_array($assignableQueues)) {
                    throw new \Exception('Invalid payload: assignable queues is not an array.');
                }

                $assignedQueueIds = [];
                $seenQueueIdsForAgent = [];

                foreach ($assignableQueues as $aq) {
                    if (! is_array($aq) || ! isset($aq['id'])) {
                        throw new \Exception('Invalid payload: malformed normalized relationship entry.');
                    }

                    if (! $this->isValidId($aq['id'])) {
                        throw new \Exception('Invalid payload: relationship missing or invalid queue ID.');
                    }

                    $qId = (int) $aq['id'];

                    if (! isset($validQueueIds[$qId])) {
                        throw new \Exception("Unknown queue relation {$qId} returned for agent {$agentId}.");
                    }

                    if (! isset($seenQueueIdsForAgent[$qId])) {
                        $assignedQueueIds[] = $qId;
                        $seenQueueIdsForAgent[$qId] = true;

                        $queueToAgents[$qId][] = $agentId;
                    }
                }

                sort($assignedQueueIds);
                $agentToQueues[$agentId] = $assignedQueueIds;
            }

            usort($agents, function ($a, $b) {
                $cmp = strcmp($a['label'], $b['label']);
                if ($cmp === 0) {
                    return $a['id'] <=> $b['id'];
                }
                return $cmp;
            });

            foreach ($queueToAgents as $qId => $aIds) {
                sort($queueToAgents[$qId]);
            }
            ksort($agentToQueues);
            ksort($queueToAgents);

            $finalQueueSnapshot = $queueService->getSnapshot();
            if (! $finalQueueSnapshot || ! isset($finalQueueSnapshot['payload']) || ! is_array($finalQueueSnapshot['payload'])) {
                throw new \Exception('Final queue snapshot missing or malformed.');
            }
            if (! isset($finalQueueSnapshot['generation']) || ! is_string($finalQueueSnapshot['generation'])) {
                throw new \Exception('Final queue snapshot generation missing or malformed.');
            }

            $finalQueueGeneration = trim($finalQueueSnapshot['generation']);
            if ($finalQueueGeneration === '' || $finalQueueGeneration !== $queueGeneration) {
                throw new \Exception('Queue generation changed or expired during agent matrix generation.');
            }

            return [
                'payload' => [
                    'queue_generation' => $queueGeneration,
                    'agents' => $agents,
                    'agent_to_queues' => $agentToQueues,
                    'queue_to_agents' => $queueToAgents,
                ],
                'item_count' => count($agents),
            ];
        }, 'artisan', config('app.znuny_prewarm.default_refresh_interval_minutes', 5));

        if ($result === ZnunyPrewarmRefreshResult::SKIPPED_LOCKED) {
            $this->warn('Znuny agents cache warmup skipped: Another refresh is already running.');
            return self::SUCCESS;
        }

        if ($result === ZnunyPrewarmRefreshResult::FAILED) {
            $this->error('Failed to warm agents cache. Error: ' . $this->getSafeFailureMessage($manager));
            return self::FAILURE;
        }

        $this->info('Successfully warmed Znuny agents cache.');
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
