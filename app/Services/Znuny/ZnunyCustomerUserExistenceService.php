<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;
use App\Services\Znuny\Cache\ZnunyCustomerUserCacheReadService;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ZnunyCustomerUserExistenceService
{
    public const SOURCE_MISSING_LOGIN = 'missing_login';

    public const SOURCE_MISSING_CUSTOMER_ID = 'missing_customer_id';

    public const SOURCE_CUSTOMER_COMPANY_NOT_FOUND = 'customer_company_not_found';

    public const SOURCE_SHORT_CACHE = 'short_cache';

    public const SOURCE_PREWARM = 'prewarm';

    public const SOURCE_LIVE = 'live';

    public const SOURCE_UNAVAILABLE = 'unavailable';

    public function __construct(
        private ZnunyCustomerUserCacheReadService $cacheReadService,
        private ZnunyClient $client,
        private ?ZnunyLookupCacheReadService $lookupCache = null,
    ) {}

    public function checkCustomerUserExistence(?string $login, ?string $customerId): array
    {
        $login = trim((string) $login);
        $customerId = trim((string) $customerId);

        if ($login === '') {
            return $this->missing(self::SOURCE_MISSING_LOGIN);
        }
        if ($customerId === '') {
            return $this->missing(self::SOURCE_MISSING_CUSTOMER_ID);
        }

        $companyGate = $this->checkCustomerCompanyGate($customerId);
        if ($companyGate !== null) {
            return $companyGate;
        }

        $snapshot = $this->cacheReadService->getSnapshot();
        $generation = $snapshot['generation'] ?? null;
        $tempValue = Cache::get($this->getTempCacheKey($generation, $login));

        if ($tempValue === true) {
            return ['registered' => true, 'source' => self::SOURCE_SHORT_CACHE, 'generation' => $generation];
        }

        if ($this->isPositivePrewarmMember($snapshot, $login)) {
            return ['registered' => true, 'source' => self::SOURCE_PREWARM, 'generation' => $generation];
        }

        if ($tempValue !== null) {
            return ['registered' => false, 'source' => self::SOURCE_SHORT_CACHE, 'generation' => $generation];
        }

        return $this->lookupLive($login, $generation, false);
    }

    public function lookupDirectly(?string $login, ?string $customerId): array
    {
        $login = trim((string) $login);

        if ($login === '') {
            return $this->missing(self::SOURCE_MISSING_LOGIN);
        }

        // CustomerID stored in the ticket may be stale. Direct verification
        // is by Login; Znuny returns the current CustomerID for reconciliation.
        $snapshot = $this->cacheReadService->getSnapshot();

        return $this->lookupLive($login, $snapshot['generation'] ?? null, true);
    }

    public function cacheShortExistence(?string $generation, string $login, bool $exists): void
    {
        $login = trim($login);
        if ($login === '') {
            return;
        }

        $ttlMinutes = max(3, (int) SettingsService::int('znuny_prewarm_customer_users_interval_minutes', 30));
        Cache::put($this->getTempCacheKey($generation, $login), $exists, $ttlMinutes * 60 * 2);
    }

    public function invalidateShortExistence(?string $generation, string $login): void
    {
        $login = trim($login);
        if ($login !== '') {
            Cache::forget($this->getTempCacheKey($generation, $login));
        }
    }

    public function getActiveGeneration(): ?string
    {
        $snapshot = $this->cacheReadService->getSnapshot();

        return $snapshot['generation'] ?? null;
    }

    private function checkCustomerCompanyGate(string $customerId): ?array
    {
        if ($this->lookupCache === null) {
            return null;
        }

        try {
            $companies = $this->lookupCache->getAuthoritativeCustomerCompanies();
        } catch (\Throwable $e) {
            Log::warning('Znuny CustomerCompany directory lookup failed', ['exception' => get_class($e)]);

            return [
                'registered' => null,
                'source' => self::SOURCE_UNAVAILABLE,
                'generation' => null,
                'reason' => 'customer_company_directory_unavailable',
            ];
        }

        if ($companies === null) {
            return [
                'registered' => null,
                'source' => self::SOURCE_UNAVAILABLE,
                'generation' => null,
                'reason' => 'customer_company_directory_unavailable',
            ];
        }

        if (! array_key_exists($customerId, $companies)) {
            return [
                'registered' => false,
                'source' => self::SOURCE_CUSTOMER_COMPANY_NOT_FOUND,
                'generation' => null,
            ];
        }

        return null;
    }

    private function isPositivePrewarmMember(?array $snapshot, string $login): bool
    {
        if (! is_array($snapshot) || ! isset($snapshot['queues']) || ! is_array($snapshot['queues'])) {
            return false;
        }

        foreach ($snapshot['queues'] as $queue) {
            if (isset($queue['options']) && is_array($queue['options']) && array_key_exists($login, $queue['options'])) {
                return true;
            }
        }

        return false;
    }

    private function lookupLive(string $login, ?string $generation, bool $authoritative): array
    {
        try {
            $user = $this->client->getCustomerUser($login);

            if (empty($user['found'])) {
                try {
                    $this->cacheShortExistence($generation, $login, false);
                } catch (\Throwable $e) {
                    Log::warning('Znuny customer user short existence cache update failed', [
                        'exists' => false,
                        'exception' => get_class($e),
                    ]);
                }

                return ['registered' => false, 'source' => self::SOURCE_LIVE, 'generation' => $generation];
            }

            $resolvedLogin = trim((string) ($user['login'] ?? ''));
            $resolvedCustomerId = trim((string) ($user['customer_id'] ?? ''));

            if ($resolvedLogin === '' || strcasecmp($resolvedLogin, $login) !== 0 || $resolvedCustomerId === '') {
                return [
                    'registered' => null,
                    'source' => self::SOURCE_UNAVAILABLE,
                    'generation' => $generation,
                    'reason' => 'invalid_authoritative_identity',
                ];
            }

            try {
                $this->cacheShortExistence($generation, $resolvedLogin, true);
            } catch (\Throwable $e) {
                Log::warning('Znuny customer user short existence cache update failed', [
                    'exists' => true,
                    'exception' => get_class($e),
                ]);
            }

            return [
                'registered' => true,
                'source' => self::SOURCE_LIVE,
                'generation' => $generation,
                'login' => $resolvedLogin,
                'customer_id' => $resolvedCustomerId,
                'status' => $user['status'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error(
                $authoritative ? 'Znuny customer user direct lookup failed' : 'Znuny customer user lookup failed',
                ['exception' => get_class($e)],
            );

            return ['registered' => null, 'source' => self::SOURCE_UNAVAILABLE, 'generation' => $generation];
        }
    }

    private function missing(string $source): array
    {
        return ['registered' => false, 'source' => $source, 'generation' => null];
    }

    private function getTempCacheKey(?string $generation, string $login): string
    {
        return 'znuny_customer_user_exists:'.($generation ?: 'fallback').':'.hash('sha256', $login);
    }
}
