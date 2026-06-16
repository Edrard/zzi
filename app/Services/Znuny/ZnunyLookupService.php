<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;

class ZnunyLookupService
{
    public function __construct(
        protected ZnunyTicketDefaultRuleService $ruleService,
        protected ZnunyClient $client,
        protected ZnunyQueueService $queueService
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

        $primaryQueueFound = false;

        if ($local['queue']) {
            try {
                $qResponse = $this->queueService->findQueueByName($local['queue']);
                if ($qResponse['found']) {
                    $result['queue'] = [
                        'found' => true,
                        'id' => $qResponse['id'],
                        'name' => $qResponse['name'],
                        'full_name' => $qResponse['full_name'],
                    ];
                    $primaryQueueFound = true;
                } else {
                    $result['warnings'] = array_merge($result['warnings'], $qResponse['warnings'] ?? []);
                }
            } catch (\Throwable $e) {
                $result['warnings'][] = "Failed to validate Queue: {$e->getMessage()}";
            }
        }

        if (! $primaryQueueFound && $local['queue']) {
            $mappings = json_decode(SettingsService::string('znuny_queue_host_mappings'), true) ?? [];
            foreach ($mappings as $mapping) {
                if (($mapping['host_prefix'] ?? '') === '') {
                    continue;
                }

                $prefix = $mapping['host_prefix'] ?? $mapping['host_pattern'] ?? '';
                $qName = $mapping['queue_name'] ?? '';

                if (strtolower($prefix) === strtolower($local['queue'])) {
                    $result['warnings'][] = "Queue mapping matched prefix: {$prefix} -> {$qName}";
                    try {
                        $qResponse = $this->queueService->findQueueByName($qName);
                        if ($qResponse['found']) {
                            $result['queue'] = [
                                'found' => true,
                                'id' => $qResponse['id'],
                                'name' => $qResponse['name'],
                                'full_name' => $qResponse['full_name'],
                            ];
                        } else {
                            $result['warnings'][] = "Mapped queue not found in Znuny: {$qName}";
                        }
                    } catch (\Throwable $e) {
                        $result['warnings'][] = "Failed to validate Mapped Queue: {$e->getMessage()}";
                    }
                    break;
                }
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
