<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;
use App\Services\Znuny\Cache\ZnunyAgentCacheReadService;
use App\Services\Znuny\Cache\ZnunyCustomerUserCacheReadService;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use App\Services\Znuny\Cache\ZnunyQueueCacheReadService;
use Illuminate\Support\Facades\Log;
use Throwable;

class ZnunyCachedLookupService
{
    protected ZnunyClient $client;

    protected ZnunyQueueHostMappingService $mappingService;

    protected ZnunyQueueCacheReadService $queueReader;

    protected ZnunyAgentCacheReadService $agentReader;

    protected ZnunyLookupCacheReadService $lookupReader;

    protected ZnunyCustomerUserCacheReadService $customerUserReader;

    public function __construct(
        ZnunyClient $client,
        ZnunyQueueHostMappingService $mappingService,
        ZnunyQueueCacheReadService $queueReader,
        ZnunyAgentCacheReadService $agentReader,
        ZnunyLookupCacheReadService $lookupReader,
        ZnunyCustomerUserCacheReadService $customerUserReader
    ) {
        $this->client = $client;
        $this->mappingService = $mappingService;
        $this->queueReader = $queueReader;
        $this->agentReader = $agentReader;
        $this->lookupReader = $lookupReader;
        $this->customerUserReader = $customerUserReader;
    }

    public function getGlobalQueueExclusionRegexes(): array
    {
        return app(ZnunyUiFilterService::class)->getQueueExclusionRegexes();
    }

    public function isQueueExcluded(array $queue, array $regexes = []): bool
    {
        $name = $queue['name'] ?? '';
        $fullName = $queue['full_name'] ?? $queue['label'] ?? null;

        return app(ZnunyUiFilterService::class)->isQueueExcluded($name, $fullName);
    }

    public function getAllQueues(): array
    {
        try {
            return $this->queueReader->getQueues();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    public function getFilteredQueueOptions(): array
    {
        $start = microtime(true);
        try {
            $all = $this->getAllQueues();
            $filterService = app(ZnunyUiFilterService::class);

            $filtered = [];
            foreach ($all as $queue) {
                $name = $queue['name'] ?? '';
                $fullName = $queue['full_name'] ?? $queue['label'] ?? null;
                if (! $filterService->isQueueExcluded($name, $fullName)) {
                    $filtered[$queue['name']] = $queue['label'];
                }
            }

            $duration = round((microtime(true) - $start) * 1000, 2);
            Log::debug('ZnunyCachedLookupService::getFilteredQueueOptions', [
                'cache' => 'N/A (uses getAllQueues)',
                'duration_ms' => $duration,
                'count' => count($filtered),
            ]);

            return $filtered;
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    public function getAssignableOwnerOptionsForQueue(string $queueName, bool $throwOnFailure = false): array
    {
        if (empty(trim($queueName))) {
            return [];
        }

        $start = microtime(true);
        try {
            // 1. Find Queue ID
            $all = $this->getAllQueues(); // Also using cached queues here!
            $queueId = null;
            foreach ($all as $q) {
                if ($q['name'] === $queueName) {
                    $queueId = $q['id'];
                    break;
                }
            }

            if (! $queueId) {
                return [];
            }

            // 2. Fetch assignable users for this Queue
            $agentIds = $this->agentReader->getAgentIdsForQueue((int) $queueId);
            $agents = $this->agentReader->getAgents();

            $rawUsers = [];
            foreach ($agentIds as $id) {
                foreach ($agents as $agent) {
                    if (($agent['id'] ?? null) === $id) {
                        $rawUsers[] = $agent;
                        break;
                    }
                }
            }

            $options = [];
            foreach ($rawUsers as $user) {
                if (isset($user['id']) && $user['id'] > 0 && isset($user['label'])) {
                    $options[$user['id']] = $user['label'];
                }
            }

            $result = app(ZnunyUiFilterService::class)->filterOwnerOptionsForUi($options, $rawUsers);

            $duration = round((microtime(true) - $start) * 1000, 2);
            Log::debug('ZnunyCachedLookupService::getAssignableOwnerOptionsForQueue', [
                'queue' => md5($queueName),
                'cache' => 'N/A (uses readers)',
                'duration_ms' => $duration,
                'count_raw' => count($options),
                'count_filtered' => count($result),
            ]);

            return $result;
        } catch (Throwable $e) {
            report($e);

            if ($throwOnFailure) {
                throw $e;
            }

            return [];
        }
    }

    public function getAssignableHumanOwnerOptionsForQueue(?string $queueName, bool $throwOnFailure = false): array
    {
        return app(ZnunyAssignmentDependencyService::class)->getOwnerOptionsForQueue($queueName);
    }

    public function getCanonicalOwnerLabel(int $ownerId, ?string $fallback = null): string
    {
        try {
            $owners = $this->getAssignableHumanOwnerOptionsForQueue(null);
            $canonical = $owners[$ownerId] ?? $owners[(string) $ownerId] ?? null;
            if (! empty($canonical)) {
                return (string) $canonical;
            }
        } catch (\Throwable $e) {
            // Safe fallback
        }

        $fallback = trim((string) $fallback);
        if ($fallback !== '' && $fallback !== (string) $ownerId) {
            return $fallback;
        }

        return "Owner ID: {$ownerId}";
    }

    private function normalizeDictionaryOptions(array $items, array $nameKeys = ['name', 'Name', 'label', 'Label', 'value', 'Value']): array
    {
        if (isset($items['Data']) && is_array($items['Data'])) {
            $items = $items['Data'];
        } elseif (isset($items['data']) && is_array($items['data'])) {
            $items = $items['data'];
        }

        $options = [];
        foreach ($items as $key => $item) {
            if (is_string($item) || is_numeric($item)) {
                $val = (string) $item;
                if (trim($val) !== '') {
                    $options[$val] = $val;
                }

                continue;
            }

            if (is_array($item)) {
                $foundValue = null;
                foreach ($nameKeys as $k) {
                    if (isset($item[$k]) && (is_string($item[$k]) || is_numeric($item[$k]))) {
                        $foundValue = (string) $item[$k];
                        break;
                    }
                }

                if ($foundValue !== null && trim($foundValue) !== '') {
                    $options[$foundValue] = $foundValue;
                }
            }
        }

        return $options;
    }

    public function getTicketStates(): array
    {
        try {
            return $this->lookupReader->getStates();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    public function getTicketPriorities(): array
    {
        try {
            return $this->lookupReader->getPriorities();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    public function getTicketTypes(): array
    {
        try {
            return $this->lookupReader->getTypes();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    public function getCustomerUserPrimaryOptionsForQueue(string $queueName): array
    {
        $queueName = trim($queueName);
        if (empty($queueName)) {
            return [];
        }

        $start = microtime(true);
        try {
            $result = $this->customerUserReader->getOptionsForQueue($queueName);

            $duration = round((microtime(true) - $start) * 1000, 2);
            Log::debug('ZnunyCachedLookupService::getCustomerUserPrimaryOptionsForQueue', [
                'queue' => md5($queueName),
                'cache' => 'N/A (uses reader)',
                'duration_ms' => $duration,
                'count' => is_array($result) ? count($result) : 0,
            ]);

            return $result;
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    public function getCustomerUserLabel(string $login): ?string
    {
        $login = trim($login);
        if (empty($login)) {
            return null;
        }

        $start = microtime(true);
        try {
            $snapshot = $this->customerUserReader->getSnapshot();
            $label = null;

            if (is_array($snapshot) && isset($snapshot['queues']) && is_array($snapshot['queues'])) {
                foreach ($snapshot['queues'] as $q) {
                    if (is_array($q['options'] ?? null) && isset($q['options'][$login])) {
                        $label = $q['options'][$login];
                        break;
                    }
                }
            }

            $duration = round((microtime(true) - $start) * 1000, 2);
            Log::debug('ZnunyCachedLookupService::getCustomerUserLabel', [
                'login' => md5($login),
                'cache' => 'N/A (uses reader snapshot)',
                'found' => $label !== null,
                'duration_ms' => $duration,
            ]);

            return $label;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function getCustomerUserSearchTerms(string $queueName): array
    {
        $queueName = trim($queueName);
        if (empty($queueName)) {
            return [];
        }

        try {
            return $this->customerUserReader->getSearchTermsForQueue($queueName);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    public function resolveTemplateCandidate(string $queueName): ?string
    {
        $queueName = trim($queueName);
        if (empty($queueName)) {
            return null;
        }

        $start = microtime(true);
        try {
            $options = $this->customerUserReader->getOptionsForQueue($queueName);

            if (empty($options)) {
                return null;
            }

            $ruleService = app(ZnunyTicketDefaultRuleService::class);
            $accepted = $this->resolveCandidateFromSourceName($queueName, $options, $ruleService);

            if ($accepted === null) {
                $mappings = json_decode(SettingsService::string('znuny_queue_host_mappings'), true);
                if (is_array($mappings)) {
                    $fallbackCandidates = [];
                    foreach ($mappings as $mapping) {
                        if (! is_array($mapping)) {
                            continue;
                        }
                        $prefixRaw = $mapping['host_prefix'] ?? null;
                        $qNameRaw = $mapping['queue_name'] ?? null;

                        if (! is_string($prefixRaw) || ! is_string($qNameRaw)) {
                            continue;
                        }

                        $prefix = trim($prefixRaw);
                        $qName = trim($qNameRaw);

                        if ($prefix !== '' && $qName !== '' && strcasecmp($qName, $queueName) === 0) {
                            $mappedAccepted = $this->resolveCandidateFromSourceName($prefix, $options, $ruleService);
                            if ($mappedAccepted !== null) {
                                $fallbackCandidates[strtolower($mappedAccepted)] = $mappedAccepted;
                            }
                        }
                    }
                    if (count($fallbackCandidates) === 1) {
                        $accepted = reset($fallbackCandidates);
                    }
                }
            }

            $duration = round((microtime(true) - $start) * 1000, 2);
            Log::debug('ZnunyCachedLookupService::resolveTemplateCandidate', [
                'queue' => md5($queueName),
                'cache' => 'N/A (uses reader)',
                'duration_ms' => $duration,
            ]);

            return $accepted;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function resolveCandidateFromSourceName(string $sourceName, array $options, ZnunyTicketDefaultRuleService $ruleService): ?string
    {
        $accepted = null;
        $words = preg_split('/\s+/u', $sourceName);
        $wordCount = count($words);

        if ($wordCount === 1) {
            $expected = $ruleService->customerUserFromQueue($sourceName);
            if (! empty($expected)) {
                foreach (array_keys($options) as $login) {
                    if (strcasecmp((string) $login, $expected) === 0) {
                        $accepted = (string) $login;
                        break;
                    }
                }
            }
        } else {
            $noSpaceQueue = preg_replace('/\s+/u', '', $sourceName);
            $expected = $ruleService->customerUserFromQueue($noSpaceQueue);

            $exactMatch = null;
            if (! empty($expected)) {
                foreach (array_keys($options) as $login) {
                    if (strcasecmp((string) $login, $expected) === 0) {
                        $exactMatch = (string) $login;
                        break;
                    }
                }
            }

            if ($exactMatch !== null) {
                $accepted = $exactMatch;
            } else {
                $template = SettingsService::string('znuny_customer_user_from_queue_template');
                if (! empty($template) && str_starts_with($template, '<queue>')) {
                    $suffix = substr($template, 7);
                    if ($suffix !== '') {
                        $firstWord = $words[0];
                        $secondWord = $words[1] ?? '';

                        $pattern = '/^'.preg_quote($firstWord, '/').'.*'.preg_quote($suffix, '/').'$/ui';
                        $candidates = [];
                        foreach (array_keys($options) as $login) {
                            if (preg_match($pattern, (string) $login)) {
                                $candidates[] = (string) $login;
                            }
                        }

                        if (count($candidates) === 1) {
                            $accepted = $candidates[0];
                        } elseif (count($candidates) > 1) {
                            $secondWordCandidates = [];
                            foreach ($candidates as $c) {
                                if (mb_stripos($c, $secondWord) !== false) {
                                    $secondWordCandidates[] = $c;
                                }
                            }

                            if (count($secondWordCandidates) === 1) {
                                $accepted = $secondWordCandidates[0];
                            } elseif (count($secondWordCandidates) > 1) {
                                $accepted = $this->sortCandidates($secondWordCandidates)[0];
                            } else {
                                $accepted = $this->sortCandidates($candidates)[0];
                            }
                        }
                    }
                }
            }
        }

        return $accepted;
    }

    private function sortCandidates(array $candidates): array
    {
        usort($candidates, function ($a, $b) {
            $cmp = strnatcasecmp($a, $b);
            if ($cmp === 0) {
                return strcmp($a, $b);
            }

            return $cmp;
        });

        return $candidates;
    }

    public function searchCustomerUserOptions(string $search, int $limit = 20): array
    {
        $search = trim($search);
        if (empty($search)) {
            return [];
        }

        $safeLimit = max(1, min(50, $limit));

        try {
            $results = $this->client->searchCustomerUsers($search, $safeLimit);
            $options = [];

            foreach ($results as $res) {
                $loginRaw = $res['login'] ?? null;
                $login = (is_scalar($loginRaw) || $loginRaw instanceof \Stringable) ? trim((string) $loginRaw) : '';

                if ($login === '') {
                    continue;
                }

                $labelRaw = $res['label'] ?? null;
                $label = (is_scalar($labelRaw) || $labelRaw instanceof \Stringable) ? trim((string) $labelRaw) : '';

                if ($label === '') {
                    $label = $login;
                }

                $options[$login] = $label;
            }

            return $options;
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * @return array{available: bool, status: string}
     */
    public function getPrewarmDatasetState(string $dataset): array
    {
        $reader = match ($dataset) {
            'queues' => $this->queueReader,
            'agents' => $this->agentReader,
            'lookups' => $this->lookupReader,
            'customer_users' => $this->customerUserReader,
            default => null,
        };

        if ($reader === null) {
            return ['available' => false, 'status' => 'unknown'];
        }

        try {
            $snapshot = $reader->getSnapshot();
            $metadata = $reader->getMetadata();

            $statusRaw = $metadata['status'] ?? null;
            $allowedStatuses = ['missing', 'refreshing', 'ready', 'stale', 'failed'];

            if (is_string($statusRaw) && in_array($statusRaw, $allowedStatuses, true)) {
                $status = $statusRaw;
            } else {
                $status = 'unknown';
            }

            $available = $snapshot !== null;

            return [
                'available' => $available,
                'status' => $status,
            ];
        } catch (Throwable $e) {
            report($e);

            return ['available' => false, 'status' => 'failed'];
        }
    }
}
