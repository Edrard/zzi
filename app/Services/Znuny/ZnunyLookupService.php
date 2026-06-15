<?php

namespace App\Services\Znuny;

class ZnunyLookupService
{
    public function __construct(
        protected ZnunyTicketDefaultRuleService $ruleService,
        protected ZnunyClient $client
    ) {}

    public function resolveTicketDefaultCandidates(string $hostName): array
    {
        $local = $this->ruleService->resolveCandidates($hostName);

        $result = [
            'input' => [
                'host_name' => $hostName,
            ],
            'detected' => [
                'queue_name' => $local['queue'],
                'customer_user_login' => $local['customer_user'],
            ],
            'queue' => [
                'found' => false,
            ],
            'customer_user' => [
                'found' => false,
            ],
            'warnings' => $local['warnings'] ?? [],
        ];

        if ($local['queue']) {
            try {
                $qResponse = $this->client->getQueueByName($local['queue']);
                if ($qResponse['found']) {
                    $result['queue'] = [
                        'found' => true,
                        'id' => $qResponse['id'],
                        'name' => $qResponse['name'],
                        'full_name' => $qResponse['full_name'],
                    ];
                } else {
                    $result['warnings'] = array_merge($result['warnings'], $qResponse['warnings'] ?? []);
                }
            } catch (\Throwable $e) {
                $result['warnings'][] = "Failed to validate Queue: {$e->getMessage()}";
            }
        }

        if ($local['customer_user']) {
            try {
                $cuResponse = $this->client->getCustomerUser($local['customer_user']);
                if ($cuResponse['found']) {
                    $result['customer_user'] = [
                        'found' => true,
                        'login' => $cuResponse['login'],
                        'customer_id' => $cuResponse['customer_id'],
                    ];
                } else {
                    $result['warnings'] = array_merge($result['warnings'], $cuResponse['warnings'] ?? []);
                }
            } catch (\Throwable $e) {
                $result['warnings'][] = "Failed to validate CustomerUser: {$e->getMessage()}";
            }
        }

        return $result;
    }
}
