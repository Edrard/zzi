<?php

namespace App\Services\Znuny;

class ZnunyTicketModalStateBuilder
{
    public function __construct(
        protected ZnunyAgentService $agentService,
        protected ZnunyQueueService $queueService,
        protected ZnunyLookupService $lookupService,
        protected ZnunyTicketAdvancedDefaultsService $defaultsService,
        protected ZnunyCachedLookupService $cachedLookupService
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

        $datasets = ['queues', 'agents', 'customer_users', 'lookups'];
        foreach ($datasets as $dataset) {
            $state = $this->cachedLookupService->getPrewarmDatasetState($dataset);
            if ($state['available'] && $state['status'] === 'ready') {
                continue;
            }

            $label = __('znuny_data_status.datasets.'.$dataset);

            if (! $state['available']) {
                if ($dataset === 'customer_users') {
                    $warnings[] = "{$label}: ".__('znuny_data_status.consumer.customer_users_unavailable_search_live');
                } else {
                    $warnings[] = "{$label}: ".__('znuny_data_status.consumer.unavailable');
                }
            } elseif ($state['status'] === 'stale') {
                $warnings[] = "{$label}: ".__('znuny_data_status.consumer.stale');
            } elseif ($state['status'] === 'refreshing') {
                $warnings[] = "{$label}: ".__('znuny_data_status.consumer.refreshing');
            }
        }

        try {
            $candidates = $this->lookupService->resolveTicketDefaultCandidates($hostName);
            if ($candidates['queue']['found']) {
                $defaultQueue = $candidates['queue']['name'];

                if (app(ZnunyUiFilterService::class)->isQueueExcluded($defaultQueue, $candidates['queue']['full_name'] ?? null)) {
                    $warnings[] = "Default queue '{$defaultQueue}' is excluded by your queue filters. Please select a different queue.";
                    $defaultQueue = null;
                } else {
                    $customerUserOptions = $this->cachedLookupService->getCustomerUserPrimaryOptionsForQueue($defaultQueue);
                }
            }
            if ($candidates['customer_user']['found']) {
                $defaultCustomerUser = $candidates['customer_user']['login'];
                if (! isset($customerUserOptions[$defaultCustomerUser])) {
                    $labelCache = $this->cachedLookupService->getCustomerUserLabel($defaultCustomerUser) ?? $defaultCustomerUser;
                    $customerUserOptions[$defaultCustomerUser] = $labelCache;
                }
            }
            $notes = $candidates['notes'] ?? [];
            $warnings = array_merge($warnings, $candidates['warnings'] ?? []);
        } catch (\Throwable $e) {
            $warnings[] = 'Lookup failed: '.$e->getMessage();
        }

        $warnings = array_values(array_unique($warnings));

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
