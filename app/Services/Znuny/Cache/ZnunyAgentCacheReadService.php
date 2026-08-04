<?php

namespace App\Services\Znuny\Cache;

class ZnunyAgentCacheReadService
{
    private PrewarmSnapshotManager $manager;
    private ZnunyQueueCacheReadService $queueService;

    public function __construct(ZnunyQueueCacheReadService $queueService)
    {
        $this->manager = new PrewarmSnapshotManager('agents');
        $this->queueService = $queueService;
    }

    /**
     * Get the coherent agent snapshot, enforcing strict cross-generation
     * dependency matching against the current queue snapshot.
     */
    public function getSnapshot(): ?array
    {
        $agentSnapshot = $this->manager->readActiveSnapshot();
        if (! $agentSnapshot) {
            return null;
        }

        $payload = $agentSnapshot['payload'];
        if (! isset($payload['queue_generation']) || ! is_string($payload['queue_generation'])) {
            return null;
        }

        $queueGenFromAgent = trim($payload['queue_generation']);
        if ($queueGenFromAgent === '') {
            return null;
        }

        $queueSnapshot = $this->queueService->getSnapshot();
        if (! $queueSnapshot || ! isset($queueSnapshot['payload']) || ! is_array($queueSnapshot['payload'])) {
            return null;
        }

        if (! isset($queueSnapshot['generation']) || ! is_string($queueSnapshot['generation'])) {
            return null;
        }

        $queueGenFromDependency = trim($queueSnapshot['generation']);
        if ($queueGenFromDependency === '') {
            return null;
        }

        if ($queueGenFromAgent !== $queueGenFromDependency) {
            return null;
        }

        if (! isset($payload['agents']) || ! is_array($payload['agents'])) {
            return null;
        }

        if (! isset($payload['agent_to_queues']) || ! is_array($payload['agent_to_queues'])) {
            return null;
        }

        if (! isset($payload['queue_to_agents']) || ! is_array($payload['queue_to_agents'])) {
            return null;
        }

        return [
            'generation' => $agentSnapshot['generation'],
            'queue_generation' => $queueGenFromAgent,
            'agents' => $payload['agents'],
            'agent_to_queues' => $payload['agent_to_queues'],
            'queue_to_agents' => $payload['queue_to_agents'],
            'metadata' => $agentSnapshot['metadata'],
        ];
    }

    /**
     * Get all active agents from the prewarmed cache.
     * Returns an empty array if the snapshot is missing.
     */
    public function getAgents(): array
    {
        $snapshot = $this->getSnapshot();
        return $snapshot ? $snapshot['agents'] : [];
    }

    /**
     * Get the full agent ID -> array of queue IDs map.
     */
    public function getAgentToQueuesMap(): array
    {
        $snapshot = $this->getSnapshot();
        return $snapshot ? $snapshot['agent_to_queues'] : [];
    }

    /**
     * Get the full queue ID -> array of agent IDs map.
     */
    public function getQueueToAgentsMap(): array
    {
        $snapshot = $this->getSnapshot();
        return $snapshot ? $snapshot['queue_to_agents'] : [];
    }

    /**
     * Get the array of queue IDs assignable to a specific agent.
     * Returns an empty array if the agent is unknown or snapshot is missing.
     */
    public function getQueueIdsForAgent(int $agentId): array
    {
        $map = $this->getAgentToQueuesMap();
        return (isset($map[$agentId]) && is_array($map[$agentId])) ? $map[$agentId] : [];
    }

    /**
     * Get the array of agent IDs assignable to a specific queue.
     * Returns an empty array if the queue is unknown or snapshot is missing.
     */
    public function getAgentIdsForQueue(int $queueId): array
    {
        $map = $this->getQueueToAgentsMap();
        return (isset($map[$queueId]) && is_array($map[$queueId])) ? $map[$queueId] : [];
    }

    /**
     * Get the metadata/status of the agents prewarm dataset.
     */
    public function getMetadata(): array
    {
        return $this->manager->readMetadata();
    }
}
