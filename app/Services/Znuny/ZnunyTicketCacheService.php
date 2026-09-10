<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use App\Support\Polling\UiPollInterval;
use Illuminate\Support\Facades\Redis;

class ZnunyTicketCacheService
{
    public function __construct(
        private readonly ZnunyLookupCacheReadService $lookupCache,
        private readonly ZnunyCustomerUserExistenceService $existenceService
    ) {}

    protected function isEnabled(): bool
    {
        return SettingsService::bool('znuny_ticket_workspace_enabled', true) ?? true;
    }

    protected function getTtl(): int
    {
        $configuredActiveTtlMinutes = SettingsService::int('znuny_ticket_cache_ttl_minutes', 10) ?? 10;
        $cacheRefreshIntervalMinutes = SettingsService::int('znuny_ticket_cache_refresh_interval_minutes', 5) ?? 5;
        $uiPollIntervalSeconds = UiPollInterval::getSeconds();

        $configuredActiveTtlSeconds = $configuredActiveTtlMinutes * 60;
        $safeRefreshTtlSeconds = ($cacheRefreshIntervalMinutes * 60) + $uiPollIntervalSeconds;

        return (int) max($configuredActiveTtlSeconds, $safeRefreshTtlSeconds);
    }

    public function upsertTicket(array $ticket): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $ticketId = $ticket['TicketID'] ?? null;
        if (! $ticketId) {
            return;
        }

        $isClosed = $this->isClosedState($ticket['StateType'] ?? '');
        $ttl = $this->getTtl();

        $key = "znuny:ticket:{$ticketId}";
        $existingDataRaw = Redis::get($key);
        $existingData = $existingDataRaw ? json_decode($existingDataRaw, true) : null;

        $ticket = $this->resolveCustomerRegistration($ticket, $existingData);

        Redis::setex($key, max(1, $ttl), json_encode($ticket));

        $this->updateIndexes($ticket, $ttl);
    }

    public function getTicket(int|string $ticketId): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $key = "znuny:ticket:{$ticketId}";
        $data = Redis::get($key);

        if ($data) {
            return json_decode($data, true);
        }

        return null;
    }

    public function forgetTicket(int|string $ticketId): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $key = "znuny:ticket:{$ticketId}";
        Redis::del($key);
        $this->clearTicketIndexes($ticketId);
    }

    public function markClosedWithShortTtl(array $ticket): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $ticketId = $ticket['TicketID'] ?? null;
        if (! $ticketId) {
            return;
        }

        $ttl = $this->getTtl();
        $key = "znuny:ticket:{$ticketId}";
        $existingDataRaw = Redis::get($key);
        $existingData = $existingDataRaw ? json_decode($existingDataRaw, true) : null;

        $ticket = $this->resolveCustomerRegistration($ticket, $existingData);

        Redis::setex($key, max(1, $ttl), json_encode($ticket));
        $this->updateIndexes($ticket, $ttl);
    }

    private function normalizeInlineAttachmentCount(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function normalizeHTMLBodyArticleCount(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }

    public function upsertOrRefreshFromSearchResult(array $ticket): string
    {
        if (! $this->isEnabled()) {
            return 'skipped_disabled';
        }

        $ticketId = $ticket['TicketID'] ?? null;
        if (! $ticketId) {
            return 'skipped_missing_ticket_id';
        }

        $isClosed = $this->isClosedState($ticket['StateType'] ?? '');
        $ttl = $this->getTtl();
        $key = "znuny:ticket:{$ticketId}";

        $existingDataRaw = Redis::get($key);
        $existingData = $existingDataRaw ? json_decode($existingDataRaw, true) : null;

        $ticket = $this->resolveCustomerRegistration($ticket, $existingData);

        $newFingerprint = $ticket['SyncFingerprint'] ?? null;
        $oldFingerprint = $existingData['SyncFingerprint'] ?? null;

        $newInlineCount = $this->normalizeInlineAttachmentCount($ticket['InlineAttachmentCount'] ?? null);
        $newHtmlCount = $this->normalizeHTMLBodyArticleCount($ticket['HTMLBodyArticleCount'] ?? null);

        $hasOldInlineCount = is_array($existingData) && array_key_exists('InlineAttachmentCount', $existingData);
        $oldInlineCount = $hasOldInlineCount ? $this->normalizeInlineAttachmentCount($existingData['InlineAttachmentCount']) : null;

        $hasOldHtmlCount = is_array($existingData) && array_key_exists('HTMLBodyArticleCount', $existingData);
        $oldHtmlCount = $hasOldHtmlCount ? $this->normalizeHTMLBodyArticleCount($existingData['HTMLBodyArticleCount']) : null;

        $fingerprintUnchanged = $existingData && $newFingerprint && $oldFingerprint && $newFingerprint === $oldFingerprint;
        $inlineCountUnchanged = $hasOldInlineCount && $newInlineCount === $oldInlineCount;
        $htmlCountUnchanged = $hasOldHtmlCount && $newHtmlCount === $oldHtmlCount;

        $hasOldRegistration = is_array($existingData) && array_key_exists('customer_user_registered', $existingData);
        $oldRegistration = $hasOldRegistration ? $existingData['customer_user_registered'] : null;
        $registrationUnchanged = $hasOldRegistration && $ticket['customer_user_registered'] === $oldRegistration;

        if ($fingerprintUnchanged && $inlineCountUnchanged && $htmlCountUnchanged && $registrationUnchanged) {
            // refresh TTLs
            Redis::expire($key, max(1, $ttl));

            $graceTtl = (int) ceil($ttl * 1.5);
            $reverseKey = "znuny:ticket_indexes:{$ticketId}";
            Redis::expire($reverseKey, $graceTtl);

            $keysRaw = Redis::get($reverseKey);
            if ($keysRaw) {
                $keys = json_decode($keysRaw, true);
                if (is_array($keys)) {
                    foreach ($keys as $k) {
                        $this->extendIndexTtl($k, $graceTtl);
                    }
                }
            }

            return 'refreshed_unchanged';
        }

        // new or fingerprint changed

        Redis::setex($key, max(1, $ttl), json_encode($ticket));
        $this->updateIndexes($ticket, $ttl);

        return $existingData ? 'updated_changed' : 'cached_new';
    }

    public function mirrorConfirmedTicketIdentity(int|string $ticketId, string $customerUserId, string $customerId): void
    {
        $key = "znuny:ticket:{$ticketId}";
        $data = Redis::get($key);

        if (! $data) {
            return;
        }

        $ticket = json_decode($data, true);
        if (! is_array($ticket)) {
            return;
        }

        $ttl = Redis::ttl($key);
        if ($ttl <= 0) {
            return;
        }

        $oldCustomerUserId = trim((string) ($ticket['CustomerUserID'] ?? ''));

        $ttlMarker = SettingsService::int('znuny_ticket_cache_refresh_interval_minutes', 5) * 60;
        Redis::setex("znuny:identity_marker:{$ticketId}", $ttlMarker, 1);

        $ticket['CustomerUserID'] = $customerUserId;
        $ticket['CustomerID'] = $customerId;
        $ticket['customer_user_registered'] = true;

        Redis::setex($key, $ttl, json_encode($ticket));

        $this->updateIndexes($ticket, $ttl);
    }

    public function indexKeysForTicket(array $ticket): array
    {
        $ticketId = $ticket['TicketID'] ?? null;
        if (! $ticketId) {
            return [];
        }

        $keys = [];
        if (! empty($ticket['QueueID'])) {
            $keys[] = "znuny:index:queue:{$ticket['QueueID']}";
        }
        if (! empty($ticket['OwnerID'])) {
            $keys[] = "znuny:index:owner:{$ticket['OwnerID']}";
        }
        if (! empty($ticket['StateID'])) {
            $keys[] = "znuny:index:state:{$ticket['StateID']}";
        }
        if (! empty($ticket['StateType'])) {
            $type = strtolower($ticket['StateType']);
            $keys[] = "znuny:index:statetype:{$type}";
        }
        if (! empty($ticket['PriorityID'])) {
            $keys[] = "znuny:index:priority:{$ticket['PriorityID']}";
        }
        if (! empty($ticket['TypeID'])) {
            $keys[] = "znuny:index:type:{$ticket['TypeID']}";
        }
        if (! empty($ticket['ServiceID'])) {
            $keys[] = "znuny:index:service:{$ticket['ServiceID']}";
        }
        if (! empty($ticket['SLAID'])) {
            $keys[] = "znuny:index:sla:{$ticket['SLAID']}";
        }
        if (! empty($ticket['CustomerUserID'])) {
            $login = strtolower(trim((string) $ticket['CustomerUserID']));
            if ($login !== '') {
                $keys[] = "znuny:index:customer_user:{$login}";
            }
        }

        return $keys;
    }

    protected function updateIndexes(array $ticket, int $ttl): void
    {
        $ticketId = $ticket['TicketID'] ?? null;
        if (! $ticketId) {
            return;
        }

        $keys = $this->indexKeysForTicket($ticket);
        $timestamp = time();
        $graceTtl = (int) ceil($ttl * 1.5);

        // Maintain a reverse lookup to easily clear later
        $reverseKey = "znuny:ticket_indexes:{$ticketId}";
        $oldKeysRaw = Redis::get($reverseKey);
        $oldKeys = $oldKeysRaw ? json_decode($oldKeysRaw, true) : [];

        // Clear ticket from old index keys that are no longer applicable
        $keysToRemove = array_diff($oldKeys, $keys);
        foreach ($keysToRemove as $oldKey) {
            Redis::zrem($oldKey, $ticketId);
        }

        // Add ticket to current index keys
        foreach ($keys as $k) {
            Redis::zadd($k, $timestamp, $ticketId);
            $this->extendIndexTtl($k, $graceTtl);
        }

        Redis::setex($reverseKey, $graceTtl, json_encode($keys));
    }

    public function extendIndexTtl(string $key, int $requiredTtl): void
    {
        if ($requiredTtl <= 0) {
            return;
        }

        $currentTtl = Redis::ttl($key);

        if ($currentTtl === -1 || $currentTtl === -2 || $currentTtl < $requiredTtl) {
            Redis::expire($key, $requiredTtl);
        }
    }

    public function cleanStaleActiveIndexMembers(array $activeStateTypes = []): int
    {
        if (empty($activeStateTypes)) {
            $activeStateTypeIdsJson = SettingsService::string('znuny_ticket_workspace_active_state_type_ids', '[]');
            $activeStateTypeIds = json_decode($activeStateTypeIdsJson, true) ?? [];
            if (is_array($activeStateTypeIds) && ! empty($activeStateTypeIds)) {
                $activeStateTypes = app(ZnunyTicketWorkspaceStateTypeMapper::class)->mapInternalIdsToZnunyTypes($activeStateTypeIds);
            }
        }

        if (empty($activeStateTypes)) {
            $activeStateTypes = ['new', 'open', 'pending reminder', 'pending auto'];
        }

        $candidateIndexMap = [];
        foreach ($activeStateTypes as $st) {
            if (empty($st)) {
                continue;
            }
            $indexKey = 'znuny:index:statetype:'.strtolower($st);
            $ids = Redis::zrange($indexKey, 0, -1);
            if (is_array($ids)) {
                foreach ($ids as $id) {
                    $idStr = (string) $id;
                    $candidateIndexMap[$idStr][] = $indexKey;
                }
            }
        }

        $candidateIds = array_keys($candidateIndexMap);
        if (empty($candidateIds)) {
            return 0;
        }

        $staleCount = 0;
        $chunks = array_chunk($candidateIds, 500);
        foreach ($chunks as $chunk) {
            $keys = array_map(fn ($id) => "znuny:ticket:{$id}", $chunk);
            $payloads = Redis::mget($keys);

            foreach ($chunk as $idx => $id) {
                $payload = $payloads[$idx] ?? null;
                $isLive = false;

                if ($payload && is_string($payload)) {
                    $decoded = json_decode($payload, true);
                    if (is_array($decoded) && ! empty($decoded['TicketID']) && (string) $decoded['TicketID'] === (string) $id) {
                        $isLive = true;
                    }
                }

                if ($isLive) {
                    continue;
                }

                $staleCount++;

                $cleanupKeys = $candidateIndexMap[$id] ?? [];

                $reverseKey = "znuny:ticket_indexes:{$id}";
                $reverseRaw = Redis::get($reverseKey);

                if ($reverseRaw !== null && $reverseRaw !== false) {
                    $indexes = json_decode((string) $reverseRaw, true);
                    if (is_array($indexes)) {
                        foreach ($indexes as $idxKey) {
                            if (is_string($idxKey) && $idxKey !== '') {
                                $cleanupKeys[] = $idxKey;
                            }
                        }
                    }
                    Redis::del($reverseKey);
                }

                $cleanupKeys = array_values(array_unique($cleanupKeys));
                foreach ($cleanupKeys as $idxKey) {
                    Redis::zrem($idxKey, $id);
                }

                if ($payload && ! $isLive) {
                    Redis::del("znuny:ticket:{$id}");
                }
            }
        }

        return $staleCount;
    }

    public function clearTicketIndexes(int|string $ticketId): void
    {
        $reverseKey = "znuny:ticket_indexes:{$ticketId}";
        $keysRaw = Redis::get($reverseKey);
        if ($keysRaw) {
            $keys = json_decode($keysRaw, true);
            foreach ($keys as $k) {
                Redis::zrem($k, $ticketId);
            }
            Redis::del($reverseKey);
        }
    }

    protected function isClosedState(string $stateType): bool
    {
        return in_array(strtolower($stateType), ['closed', 'merged'], true);
    }

    private function resolveCustomerRegistration(array $ticket, mixed $existingData = null): array
    {
        $existingData = is_array($existingData) ? $existingData : null;
        $ticketId = $ticket['TicketID'] ?? null;

        if ($ticketId && is_array($existingData)) {
            if (Redis::exists("znuny:identity_marker:{$ticketId}")) {
                $ticket['CustomerUserID'] = trim((string) ($existingData['CustomerUserID'] ?? ''));
                $ticket['CustomerID'] = trim((string) ($existingData['CustomerID'] ?? ''));
                $ticket['customer_user_registered'] = $existingData['customer_user_registered'] ?? true;

                return $ticket;
            }
        }

        return $this->enrichTicketWithCustomerRegistration($ticket, $existingData);
    }

    private function enrichTicketWithCustomerRegistration(array $ticket, ?array $existingData = null): array
    {
        $customerId = trim((string) ($ticket['CustomerID'] ?? ''));
        $customerUserId = trim((string) ($ticket['CustomerUserID'] ?? ''));

        $status = $this->existenceService->checkCustomerUserExistence($customerUserId, $customerId);

        if ($status['registered'] === null) {
            if (is_array($existingData) && array_key_exists('customer_user_registered', $existingData)) {
                $ticket['customer_user_registered'] = $existingData['customer_user_registered'];
            } else {
                $ticket['customer_user_registered'] = null;
            }
        } else {
            $ticket['customer_user_registered'] = $status['registered'];
        }

        return $ticket;
    }
}
