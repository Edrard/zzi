<?php

namespace App\Services\Znuny;

class ZnunyAssignmentDependencyService
{
    public function __construct(
        protected ZnunyClient $client,
        protected ZnunyAgentService $agentService
    ) {}

    public function getAssignableAgentsForQueue(?string $queueName): array
    {
        if (empty($queueName)) {
            return $this->agentService->getSelectableAgents();
        }

        $queue = $this->client->getQueueByName($queueName);
        if (empty($queue['id'])) {
            return $this->agentService->getSelectableAgents();
        }

        return $this->agentService->getSelectableAssignableAgentsForQueue($queue['id']);
    }

    /**
     * @return array<string|int, string> Key is agent ID, value is label
     */
    public function getOwnerOptionsForQueue(?string $queueName): array
    {
        if (empty($queueName)) {
            $agents = $this->agentService->getSelectableAgents();

            $options = collect($agents)->pluck('label', 'id')->toArray();

            return app(ZnunyUiFilterService::class)->filterOwnerOptionsForUi($options, $agents);
        }

        $queue = $this->client->getQueueByName($queueName);
        if (empty($queue['id'])) {
            $agents = $this->agentService->getSelectableAgents();

            $options = collect($agents)->pluck('label', 'id')->toArray();

            return app(ZnunyUiFilterService::class)->filterOwnerOptionsForUi($options, $agents);
        }

        $agents = $this->agentService->getSelectableAssignableAgentsForQueue($queue['id']);

        $options = collect($agents)->pluck('label', 'id')->toArray();

        return app(ZnunyUiFilterService::class)->filterOwnerOptionsForUi($options, $agents);
    }

    /**
     * @return array<string|int, string> Key is agent ID, value is label
     */
    public function getStrictOwnerOptionsForQueue(?string $queueName): array
    {
        if (empty($queueName)) {
            return [];
        }

        $queue = $this->client->getQueueByName($queueName);
        if (empty($queue['id'])) {
            return [];
        }

        $agents = $this->agentService->getSelectableAssignableAgentsForQueue($queue['id']);

        $options = collect($agents)->pluck('label', 'id')->toArray();

        return app(ZnunyUiFilterService::class)->filterOwnerOptionsForUi($options, $agents);
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

            $options = collect($queues)->pluck('label', 'name')->toArray();

            return app(ZnunyUiFilterService::class)->filterQueuesForUi($options);
        }

        $queues = $this->client->getAgentAssignableQueues((int) $ownerId);

        $options = collect($queues)->pluck('label', 'name')->toArray();

        return app(ZnunyUiFilterService::class)->filterQueuesForUi($options);
    }

    public function isOwnerValidForQueue(?string $ownerId, ?string $queueName): bool
    {
        if (empty($ownerId) || empty($queueName)) {
            return true;
        }

        $options = $this->getOwnerOptionsForQueue($queueName);

        return array_key_exists((string) $ownerId, $options);
    }

    public function isOwnerStrictlyValidForQueue(?string $ownerId, ?string $queueName): bool
    {
        if (empty($ownerId) || empty($queueName)) {
            return false;
        }

        $options = $this->getStrictOwnerOptionsForQueue($queueName);

        return array_key_exists((string) $ownerId, $options);
    }

    /**
     * @return array<string, string> Key is agent login, value is label
     */
    public function getOwnerLoginOptionsForQueue(?string $queueName): array
    {
        if (empty($queueName)) {
            $agents = $this->agentService->getSelectableAgents();

            $options = collect($agents)->pluck('label', 'login')->toArray();

            return app(ZnunyUiFilterService::class)->filterOwnerOptionsForUi($options, $agents);
        }

        $queue = $this->client->getQueueByName($queueName);
        if (empty($queue['id'])) {
            $agents = $this->agentService->getSelectableAgents();

            $options = collect($agents)->pluck('label', 'login')->toArray();

            return app(ZnunyUiFilterService::class)->filterOwnerOptionsForUi($options, $agents);
        }

        $agents = $this->agentService->getSelectableAssignableAgentsForQueue($queue['id']);

        $options = collect($agents)->pluck('label', 'login')->toArray();

        return app(ZnunyUiFilterService::class)->filterOwnerOptionsForUi($options, $agents);
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

            $options = collect($queues)->pluck('label', 'name')->toArray();

            return app(ZnunyUiFilterService::class)->filterQueuesForUi($options);
        }

        if (app(ZnunyUiFilterService::class)->isAgentLoginExcluded($ownerLogin)) {
            return [];
        }

        $agents = $this->client->getAgents();
        $agentId = collect($agents)->firstWhere('login', $ownerLogin)['id'] ?? null;
        if (! $agentId) {
            $queues = collect($this->client->getQueues())
                ->filter(fn ($queue) => ($queue['valid_id'] ?? 1) === 1)
                ->values()
                ->toArray();

            $options = collect($queues)->pluck('label', 'name')->toArray();

            return app(ZnunyUiFilterService::class)->filterQueuesForUi($options);
        }

        $queues = $this->client->getAgentAssignableQueues($agentId);

        $options = collect($queues)->pluck('label', 'name')->toArray();

        return app(ZnunyUiFilterService::class)->filterQueuesForUi($options);
    }
}
