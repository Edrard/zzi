<?php

namespace App\Services\Znuny;

class ZnunyTicketModalStateBuilder
{
    public function __construct(
        protected ZnunyAgentService $agentService,
        protected ZnunyQueueService $queueService,
        protected ZnunyLookupService $lookupService
    ) {}

    /**
     * @return array{
     *   agent_options: array<string, string>,
     *   queue_options: array<string, string>,
     *   default_owner_id: ?string,
     *   default_queue: ?string,
     *   default_customer_user: ?string,
     *   customer_user_options: array<string, string>,
     *   warnings: array<int, string>
     * }
     */
    public function buildState(string $hostName): array
    {
        $agentOptions = collect($this->agentService->getSelectableAgents())
            ->mapWithKeys(fn (array $agent) => [(string) $agent['id'] => $agent['label']])
            ->toArray();

        $queueResult = $this->queueService->getSelectableQueuesResult();
        $queueOptions = $queueResult['options'];

        $defaultOwnerId = null;
        $defaultQueue = null;
        $defaultCustomerUser = null;
        $customerUserOptions = [];
        $warnings = [];

        try {
            $candidates = $this->lookupService->resolveTicketDefaultCandidates($hostName);
            if ($candidates['queue']['found']) {
                $defaultQueue = $candidates['queue']['name'];
            }
            if ($candidates['customer_user']['found']) {
                $defaultCustomerUser = $candidates['customer_user']['login'];
                $customerUserOptions[$defaultCustomerUser] = $candidates['customer_user']['login'];
            }
            $warnings = $candidates['warnings'] ?? [];
        } catch (\Throwable $e) {
            $warnings[] = 'Lookup failed: '.$e->getMessage();
        }

        return [
            'agent_options' => $agentOptions,
            'queue_options' => $queueOptions,
            'default_owner_id' => $defaultOwnerId,
            'default_queue' => $defaultQueue,
            'default_customer_user' => $defaultCustomerUser,
            'customer_user_options' => $customerUserOptions,
            'warnings' => $warnings,
        ];
    }
}
