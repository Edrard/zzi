<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ZnunyCachedLookupService
{
    protected ZnunyClient $client;

    protected ZnunyQueueHostMappingService $mappingService;

    public function __construct(ZnunyClient $client, ZnunyQueueHostMappingService $mappingService)
    {
        $this->client = $client;
        $this->mappingService = $mappingService;
    }

    public function getCacheTtl(): int
    {
        return 60 * 60; // 1 hour in seconds
    }

    public function clearCache(): void
    {
        Cache::forget('znuny_lookup_queues_all');
        Cache::forget('znuny_lookup_queues_filtered');
        Cache::forget('znuny_lookup_states');
        Cache::forget('znuny_lookup_priorities');
        Cache::forget('znuny_lookup_types');

        // Clearing individual queue/owner keys would require tagging (Redis)
        // Since we only have file/database cache, we just wait for TTL or provide a way to clear them if possible.
        // Or we could store a "version" integer in cache and increment it to invalidate everything.
    }

    public function getCacheVersion(): int
    {
        return Cache::rememberForever('znuny_lookup_cache_version', fn () => now()->timestamp);
    }

    public function invalidateCache(): void
    {
        Cache::put('znuny_lookup_cache_version', now()->timestamp);
    }

    private function getCacheKey(string $key): string
    {
        return $key.'_v'.$this->getCacheVersion();
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
        $start = microtime(true);
        try {
            $key = $this->getCacheKey('znuny_lookup_queues_all');
            $hit = Cache::has($key);

            $result = Cache::remember($key, $this->getCacheTtl(), function () {
                return $this->client->getQueues();
            });

            $duration = round((microtime(true) - $start) * 1000, 2);
            Log::debug('ZnunyCachedLookupService::getAllQueues', [
                'cache' => $hit ? 'hit' : 'miss',
                'duration_ms' => $duration,
                'count' => is_array($result) ? count($result) : 0,
            ]);

            return $result;
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

            $filtered = [];
            foreach ($all as $queue) {
                if (! $this->isQueueExcluded($queue)) {
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
            $key = $this->getCacheKey('znuny_lookup_owners_raw_'.md5($queueName));
            $hit = Cache::has($key);

            $rawUsers = Cache::remember($key, $this->getCacheTtl(), function () use ($queueName) {
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
                return $this->client->getQueueAssignableAgents($queueId);
            });

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
                'cache' => $hit ? 'hit' : 'miss',
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
            return Cache::remember($this->getCacheKey('znuny_lookup_states'), $this->getCacheTtl(), function () {
                $states = $this->client->getTicketStates();
                $normalized = $this->normalizeDictionaryOptions($states);

                if (empty($normalized)) {
                    throw new \Exception('ZnunyClient returned empty or malformed states array.');
                }

                return $normalized;
            });
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    public function getTicketPriorities(): array
    {
        try {
            return Cache::remember($this->getCacheKey('znuny_lookup_priorities'), $this->getCacheTtl(), function () {
                $priorities = $this->client->getTicketPriorities();
                $normalized = $this->normalizeDictionaryOptions($priorities);

                if (empty($normalized)) {
                    throw new \Exception('ZnunyClient returned empty or malformed priorities array.');
                }

                return $normalized;
            });
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    public function getTicketTypes(): array
    {
        try {
            return Cache::remember($this->getCacheKey('znuny_lookup_types'), $this->getCacheTtl(), function () {
                $types = $this->client->getTicketTypes();
                $normalized = $this->normalizeDictionaryOptions($types);

                if (empty($normalized)) {
                    throw new \Exception('ZnunyClient returned empty or malformed types array.');
                }

                return $normalized;
            });
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    public function getCustomerUserPrimaryOptionsForQueue(string $queueName): array
    {
        if (empty(trim($queueName))) {
            return [];
        }

        $start = microtime(true);
        try {
            $key = $this->getCacheKey('znuny_lookup_customers_'.md5($queueName));
            $hit = Cache::has($key);

            $result = Cache::remember($key, $this->getCacheTtl(), function () use ($queueName) {
                $terms = $this->getCustomerUserSearchTerms($queueName);

                $options = [];
                foreach ($terms as $term) {
                    $results = $this->client->searchCustomerUsers($term);
                    foreach ($results as $res) {
                        if (! empty($res['login'])) {
                            $options[$res['login']] = $res['label'] ?? $res['login'];
                        }
                    }
                }

                return $options;
            });

            $duration = round((microtime(true) - $start) * 1000, 2);
            Log::debug('ZnunyCachedLookupService::getCustomerUserPrimaryOptionsForQueue', [
                'queue' => md5($queueName),
                'cache' => $hit ? 'hit' : 'miss',
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
        if (empty(trim($login))) {
            return null;
        }

        $start = microtime(true);
        $key = $this->getCacheKey('znuny_lookup_customer_label_'.md5($login));
        $cached = Cache::get($key);

        if ($cached !== null) {
            $duration = round((microtime(true) - $start) * 1000, 2);
            Log::debug('ZnunyCachedLookupService::getCustomerUserLabel', [
                'login' => md5($login),
                'cache' => 'hit',
                'duration_ms' => $duration,
            ]);

            return $cached;
        }

        try {
            $user = $this->client->getCustomerUser($login);

            if (! empty($user['found'])) {
                $label = $user['label'] ?? $login;
                Cache::put($key, $label, $this->getCacheTtl());

                $duration = round((microtime(true) - $start) * 1000, 2);
                Log::debug('ZnunyCachedLookupService::getCustomerUserLabel', [
                    'login' => md5($login),
                    'cache' => 'miss',
                    'duration_ms' => $duration,
                ]);

                return $label;
            }

            $duration = round((microtime(true) - $start) * 1000, 2);
            Log::debug('ZnunyCachedLookupService::getCustomerUserLabel', [
                'login' => md5($login),
                'cache' => 'miss',
                'duration_ms' => $duration,
            ]);

            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function getCustomerUserSearchTerms(string $queueName): array
    {
        $terms = [];
        $lowerQueueName = strtolower(trim($queueName));

        // Get actual mappings from settings directly or via service
        $mappings = [];
        try {
            $rawMappings = SettingsService::json('znuny_queue_host_mappings', []);
            $mappings = is_array($rawMappings) ? $rawMappings : [];
        } catch (Throwable $e) {
            report($e);
        }

        // 3. Queue label / full name
        $all = [];
        try {
            $all = $this->getAllQueues();
        } catch (Throwable $e) {
            report($e);
        }

        $queueLabel = null;
        $queueFullName = null;

        foreach ($all as $q) {
            if (strtolower(trim($q['name'] ?? '')) === $lowerQueueName) {
                $queueLabel = strtolower(trim($q['label'] ?? ''));
                $queueFullName = strtolower(trim($q['full_name'] ?? ''));
                break;
            }
        }

        // 1. Mapped host_prefix values
        foreach ($mappings as $mapping) {
            $q = $mapping['queue'] ?? $mapping['queue_name'] ?? $mapping['znuny_queue'] ?? $mapping['znuny_queue_name'] ?? '';
            $p = $mapping['host_prefix'] ?? $mapping['prefix'] ?? '';

            if ($q !== '' && $p !== '') {
                $lowerQ = strtolower(trim($q));
                if ($lowerQ === $lowerQueueName || $lowerQ === $queueLabel || $lowerQ === $queueFullName) {
                    $terms[] = trim($p);
                }
            }
        }

        // 2. Selected queue name
        $terms[] = $queueName;

        // Add label/fullname if different
        if (! empty($queueLabel) && $queueLabel !== $lowerQueueName) {
            foreach ($all as $q) {
                if (strtolower(trim($q['name'] ?? '')) === $lowerQueueName) {
                    $terms[] = trim($q['label'] ?? '');
                    break;
                }
            }
        }

        if (! empty($queueFullName) && $queueFullName !== $lowerQueueName && $queueFullName !== $queueLabel) {
            foreach ($all as $q) {
                if (strtolower(trim($q['name'] ?? '')) === $lowerQueueName) {
                    $terms[] = trim($q['full_name'] ?? '');
                    break;
                }
            }
        }

        // Filter empty and unique
        $terms = array_values(array_filter(array_unique($terms)));

        return $terms;
    }

    public function resolveTemplateCandidate(string $queueName): ?string
    {
        if (empty(trim($queueName))) {
            return null;
        }

        $start = microtime(true);
        try {
            $key = $this->getCacheKey('znuny_lookup_candidate_'.md5($queueName));
            $hit = Cache::has($key);

            $result = Cache::remember($key, $this->getCacheTtl(), function () use ($queueName) {
                $terms = $this->getCustomerUserSearchTerms($queueName);
                $ruleService = app(ZnunyTicketDefaultRuleService::class);

                foreach ($terms as $term) {
                    $candidate = $ruleService->customerUserFromQueue($term);
                    if ($candidate) {
                        $user = $this->client->getCustomerUser($candidate);
                        if ($user['found']) {
                            return $candidate;
                        }
                    }
                }

                return null;
            });

            $duration = round((microtime(true) - $start) * 1000, 2);
            Log::debug('ZnunyCachedLookupService::resolveTemplateCandidate', [
                'queue' => md5($queueName),
                'cache' => $hit ? 'hit' : 'miss',
                'duration_ms' => $duration,
            ]);

            return $result;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
