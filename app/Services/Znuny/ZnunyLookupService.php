<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;

class ZnunyLookupService
{
    public function __construct(
        protected ZnunyTicketDefaultRuleService $ruleService,
        protected ZnunyCachedLookupService $cachedLookupService,
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
            'notes' => [],
            'warnings' => $local['warnings'] ?? [],
        ];

        $primaryQueueFound = false;
        $primaryQueueWarnings = [];

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
                    $primaryQueueWarnings = $qResponse['warnings'] ?? [];
                }
            } catch (\Throwable $e) {
                $primaryQueueWarnings = ["Failed to validate Queue: {$e->getMessage()}"];
            }
        }

        if (! $primaryQueueFound && $local['queue']) {
            $mapped = false;
            $mappings = json_decode(SettingsService::string('znuny_queue_host_mappings'), true) ?? [];
            foreach ($mappings as $mapping) {
                if (($mapping['host_prefix'] ?? '') === '') {
                    continue;
                }

                $prefix = $mapping['host_prefix'] ?? $mapping['host_pattern'] ?? '';
                $qName = $mapping['queue_name'] ?? '';

                if (strtolower($prefix) === strtolower($local['queue'])) {
                    $mapped = true;
                    try {
                        $qResponse = $this->queueService->findQueueByName($qName);
                        if ($qResponse['found']) {
                            $result['notes'][] = "Queue resolved by prefix: {$prefix} → {$qName}";
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

            if (! $mapped && ! empty($primaryQueueWarnings)) {
                $result['warnings'] = array_merge($result['warnings'], $primaryQueueWarnings);
            }
        } elseif (! $primaryQueueFound && ! empty($primaryQueueWarnings)) {
            $result['warnings'] = array_merge($result['warnings'], $primaryQueueWarnings);
        }

        if ($result['queue']['found']) {
            $resolvedQueueName = $result['queue']['name'];
            try {
                $candidate = $this->cachedLookupService->resolveTemplateCandidate($resolvedQueueName);
                if (! empty($candidate)) {
                    $result['customer_user'] = [
                        'found' => true,
                        'login' => $candidate,
                    ];
                } else {
                    $result['warnings'][] = "CustomerUser not found in prewarm cache for queue: {$resolvedQueueName}";
                }
            } catch (\Throwable $e) {
                $result['warnings'][] = "Failed to validate CustomerUser: {$e->getMessage()}";
            }
        }

        return $result;
    }
}
