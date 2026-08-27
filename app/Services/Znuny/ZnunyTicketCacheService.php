<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use App\Support\Polling\UiPollInterval;
use Illuminate\Support\Facades\Redis;

class ZnunyTicketCacheService
{
    public function __construct(
        private readonly ZnunyLookupCacheReadService $lookupCache
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

        $ticket = $this->enrichTicketWithCustomerRegistration($ticket);

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

        $ticket = $this->enrichTicketWithCustomerRegistration($ticket);

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

        $ticket = $this->enrichTicketWithCustomerRegistration($ticket);

        $isClosed = $this->isClosedState($ticket['StateType'] ?? '');
        $ttl = $this->getTtl();
        $key = "znuny:ticket:{$ticketId}";

        $existingDataRaw = Redis::get($key);
        $existingData = $existingDataRaw ? json_decode($existingDataRaw, true) : null;

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

            $reverseKey = "znuny:ticket_indexes:{$ticketId}";
            Redis::expire($reverseKey, max($ttl, 86400 * 7));

            $keysRaw = Redis::get($reverseKey);
            if ($keysRaw) {
                $keys = json_decode($keysRaw, true);
                foreach ($keys as $k) {
                    Redis::expire($k, max($ttl, 86400 * 7));
                }
            }

            return 'refreshed_unchanged';
        }

        // new or fingerprint changed

        Redis::setex($key, max(1, $ttl), json_encode($ticket));
        $this->updateIndexes($ticket, $ttl);

        return $existingData ? 'updated_changed' : 'cached_new';
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
            Redis::expire($k, max($ttl, 86400 * 7)); // keep index around a bit longer
        }

        Redis::setex($reverseKey, max($ttl, 86400 * 7), json_encode($keys));
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

    private function enrichTicketWithCustomerRegistration(array $ticket): array
    {
        $customerId = (string) ($ticket['CustomerID'] ?? '');

        $ticket['customer_user_registered'] =
            $this->lookupCache->hasCustomerCompany($customerId);

        return $ticket;
    }
}
