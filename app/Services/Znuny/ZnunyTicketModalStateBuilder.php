<?php

namespace App\Services\Znuny;

class ZnunyTicketModalStateBuilder
{
    public function __construct(
        protected ZnunyAgentService $agentService,
        protected ZnunyQueueService $queueService,
        protected ZnunyLookupService $lookupService,
        protected ZnunyTicketAdvancedDefaultsService $defaultsService
    ) {}

    /**
     * @return array{
     *   agent_options: array<string, string>,
     *   queue_options: array<string, string>,
     *   default_owner_id: ?string,
     *   default_queue: ?string,
     *   default_customer_user: ?string,
     *   customer_user_options: array<string, string>,
     *   notes: array<int, string>,
     *   warnings: array<int, string>,
     *   priority: string,
     *   state: string,
     *   lock: string
     * }
     */
    public function buildState(string $hostName): array
    {
        $agentOptions = collect($this->agentService->getSelectableAgents())
            ->mapWithKeys(fn (array $agent) => [(string) $agent['id'] => $agent['label']])
            ->toArray();

        $queueResult = $this->queueService->getSelectableQueuesResult();
        $queueOptions = $queueResult['options'];

        $advancedDefaults = $this->defaultsService->getDefaults();

        $defaultOwnerId = null;
        $defaultQueue = null;
        $defaultCustomerUser = null;
        $customerUserOptions = [];
        $notes = [];
        $warnings = [];

        try {
            $candidates = $this->lookupService->resolveTicketDefaultCandidates($hostName);
            if ($candidates['queue']['found']) {
                $defaultQueue = $candidates['queue']['name'];

                if (app(ZnunyUiFilterService::class)->isQueueExcluded($defaultQueue, $candidates['queue']['full_name'] ?? null)) {
                    $warnings[] = "Default queue '{$defaultQueue}' is excluded by your queue filters. Please select a different queue.";
                    $defaultQueue = null;
                }
            }
            if ($candidates['customer_user']['found']) {
                $defaultCustomerUser = $candidates['customer_user']['login'];
                $customerUserOptions[$defaultCustomerUser] = $candidates['customer_user']['login'];
            }
            $notes = $candidates['notes'] ?? [];
            $warnings = array_merge($warnings, $candidates['warnings'] ?? []);
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
            'notes' => $notes,
            'warnings' => $warnings,
            'priority' => $advancedDefaults['priority'],
            'state' => $advancedDefaults['state'],
            'lock' => $advancedDefaults['lock'],
        ];
    }
}
