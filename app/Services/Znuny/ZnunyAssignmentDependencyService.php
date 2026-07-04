<?php

namespace App\Services\Znuny;

class ZnunyAssignmentDependencyService
{
    public function __construct(
        protected ZnunyClient $client,
        protected ZnunyAgentService $agentService
    ) {}

    /**
     * @return array<string|int, string> Key is agent ID, value is label
     */
    public function getOwnerOptionsForQueue(?string $queueName): array
    {
        if (empty($queueName)) {
            $agents = $this->agentService->getSelectableAgents();

            return collect($agents)->pluck('label', 'id')->toArray();
        }

        $queue = $this->client->getQueueByName($queueName);
        if (empty($queue['id'])) {
            $agents = $this->agentService->getSelectableAgents();

            return collect($agents)->pluck('label', 'id')->toArray();
        }

        $agents = $this->agentService->getSelectableAssignableAgentsForQueue($queue['id']);

        return collect($agents)->pluck('label', 'id')->toArray();
    }

    /**
     * @return array<string, string> Key is queue name, value is label
     */
    public function getQueueOptionsForOwnerId(?string $ownerId): array
    {
        if (empty($ownerId)) {
            $queues = collect($this->client->getQueues())
                ->filter(fn ($queue) => ($queue['valid_id'] ?? 1) === 1)
                ->values()
                ->toArray();

            return collect($queues)->pluck('label', 'name')->toArray();
        }

        $queues = $this->client->getAgentAssignableQueues((int) $ownerId);

        return collect($queues)->pluck('label', 'name')->toArray();
    }

    public function isOwnerValidForQueue(?string $ownerId, ?string $queueName): bool
    {
        if (empty($ownerId) || empty($queueName)) {
            return true;
        }

        $options = $this->getOwnerOptionsForQueue($queueName);

        return array_key_exists((string) $ownerId, $options);
    }

    /**
     * @return array<string, string> Key is agent login, value is label
     */
    public function getOwnerLoginOptionsForQueue(?string $queueName): array
    {
        if (empty($queueName)) {
            $agents = $this->agentService->getSelectableAgents();

            return collect($agents)->pluck('label', 'login')->toArray();
        }

        $queue = $this->client->getQueueByName($queueName);
        if (empty($queue['id'])) {
            $agents = $this->agentService->getSelectableAgents();

            return collect($agents)->pluck('label', 'login')->toArray();
        }

        $agents = $this->agentService->getSelectableAssignableAgentsForQueue($queue['id']);

        return collect($agents)->pluck('label', 'login')->toArray();
    }

    /**
     * @return array<string, string> Key is queue name, value is label
     */
    public function getQueueOptionsForOwnerLogin(?string $ownerLogin): array
    {
        if (empty($ownerLogin)) {
            $queues = collect($this->client->getQueues())
                ->filter(fn ($queue) => ($queue['valid_id'] ?? 1) === 1)
                ->values()
                ->toArray();

            return collect($queues)->pluck('label', 'name')->toArray();
        }

        if ($this->agentService->isLoginExcluded($ownerLogin)) {
            return [];
        }

        $agents = $this->client->getAgents();
        $agentId = collect($agents)->firstWhere('login', $ownerLogin)['id'] ?? null;
        if (! $agentId) {
            $queues = collect($this->client->getQueues())
                ->filter(fn ($queue) => ($queue['valid_id'] ?? 1) === 1)
                ->values()
                ->toArray();

            return collect($queues)->pluck('label', 'name')->toArray();
        }

        $queues = $this->client->getAgentAssignableQueues($agentId);

        return collect($queues)->pluck('label', 'name')->toArray();
    }
}
