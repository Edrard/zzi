<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use Illuminate\Support\Facades\Redis;

class ClosedTicketCacheService
{
    public function __construct(
        private readonly ZnunyLookupCacheReadService $lookupCache,
        private readonly ZnunyCustomerUserExistenceService $existenceService
    ) {}

    private const METADATA_KEY = 'znuny:closed_ticket:sync:metadata';

    public function getMetadata(): ?array
    {
        $data = Redis::get(self::METADATA_KEY);
        if (! $data) {
            return null;
        }

        return json_decode($data, true);
    }

    public function setMetadata(array $metadata): void
    {
        Redis::set(self::METADATA_KEY, json_encode($metadata));
    }

    public function getRecentTicketIds(): array
    {
        $keys = Redis::keys('znuny:closed_ticket:index:*');

        $prefix = config('database.redis.options.prefix', '');
        try {
            if (empty($prefix) && method_exists(Redis::client(), 'getOption')) {
                $prefix = Redis::client()->getOption(\Redis::OPT_PREFIX) ?: '';
            }
        } catch (\Throwable $e) {
        }

        $ids = [];
        if (is_array($keys)) {
            foreach ($keys as $k) {
                $unprefixed = ($prefix !== '' && str_starts_with($k, $prefix)) ? substr($k, strlen($prefix)) : $k;
                $dailyIds = Redis::zrange($unprefixed, 0, -1);
                if (! empty($dailyIds)) {
                    foreach ($dailyIds as $id) {
                        $ids[(string) $id] = true;
                    }
                }
            }
        }

        return array_keys($ids);
    }

    public function upsertTicket(array $ticket, int $retentionDays): void
    {
        if (empty($ticket['TicketID'])) {
            return;
        }

        if (empty($ticket['Created'])) {
            return;
        }

        $ticketId = $ticket['TicketID'];
        $timestamp = strtotime($ticket['Created']);

        if ($timestamp === false || $timestamp <= 0) {
            return;
        }

        $date = date('Y-m-d', $timestamp);
        $retentionSeconds = $retentionDays * 86400;

        $ticketKey = "znuny:closed_ticket:ticket:{$ticketId}";

        $oldLogin = '';
        $existingData = Redis::get($ticketKey);
        if ($existingData) {
            $existingTicket = json_decode($existingData, true);
            if (is_array($existingTicket) && ! empty($existingTicket['CustomerUserID'])) {
                $oldLogin = strtolower(trim((string) $existingTicket['CustomerUserID']));
            }
        }

        $customerId = trim((string) ($ticket['CustomerID'] ?? ''));
        $customerUserId = trim((string) ($ticket['CustomerUserID'] ?? ''));

        $protected = false;
        if (isset($existingTicket) && is_array($existingTicket)) {
            if (Redis::exists("znuny:identity_marker:{$ticketId}")) {
                $ticket['CustomerUserID'] = trim((string) ($existingTicket['CustomerUserID'] ?? ''));
                $ticket['CustomerID'] = trim((string) ($existingTicket['CustomerID'] ?? ''));
                $ticket['customer_user_registered'] = $existingTicket['customer_user_registered'] ?? true;
                $protected = true;
            }
        }

        if (! $protected) {
            $status = $this->existenceService->checkCustomerUserExistence($customerUserId, $customerId);
            if ($status['registered'] === null) {
                $ticket['customer_user_registered'] = isset($existingTicket) && array_key_exists('customer_user_registered', $existingTicket)
                    ? $existingTicket['customer_user_registered']
                    : null;
            } else {
                $ticket['customer_user_registered'] = $status['registered'];
            }
        }

        Redis::setex($ticketKey, $retentionSeconds, json_encode($ticket));

        $indexGraceSeconds = $retentionSeconds + 86400;

        $indexKey = "znuny:closed_ticket:index:{$date}";
        Redis::zadd($indexKey, $timestamp, $ticketId);
        $this->extendIndexTtl($indexKey, $indexGraceSeconds);

        $newLogin = '';
        if (! empty($ticket['CustomerUserID'])) {
            $newLogin = strtolower(trim((string) $ticket['CustomerUserID']));
            if ($newLogin !== '') {
                $userIndexKey = "znuny:closed_ticket:customer_user_index:{$newLogin}";
                Redis::zadd($userIndexKey, $timestamp, $ticketId);
                $this->extendIndexTtl($userIndexKey, $indexGraceSeconds);
            }
        }

        if ($oldLogin !== '' && $oldLogin !== $newLogin) {
            $oldUserIndexKey = "znuny:closed_ticket:customer_user_index:{$oldLogin}";
            Redis::zrem($oldUserIndexKey, $ticketId);
        }
    }

    public function getTicket(int|string $ticketId): ?array
    {
        $ticketKey = "znuny:closed_ticket:ticket:{$ticketId}";
        $data = Redis::get($ticketKey);

        if (! $data) {
            return null;
        }

        return json_decode($data, true);
    }

    public function mirrorConfirmedTicketIdentity(int|string $ticketId, string $customerUserId, string $customerId): void
    {
        $ticketKey = "znuny:closed_ticket:ticket:{$ticketId}";
        $data = Redis::get($ticketKey);

        if (! $data) {
            return;
        }

        $ticket = json_decode($data, true);
        if (! is_array($ticket)) {
            return;
        }

        $ttl = Redis::ttl($ticketKey);
        if ($ttl <= 0) {
            return;
        }

        $oldCustomerUserId = trim((string) ($ticket['CustomerUserID'] ?? ''));

        $ttlMarker = SettingsService::int('znuny_ticket_cache_refresh_interval_minutes', 5) * 60;
        Redis::setex("znuny:identity_marker:{$ticketId}", $ttlMarker, 1);

        $ticket['CustomerUserID'] = $customerUserId;
        $ticket['CustomerID'] = $customerId;
        $ticket['customer_user_registered'] = true;

        Redis::setex($ticketKey, $ttl, json_encode($ticket));

        if (! empty($ticket['Created'])) {
            $timestamp = strtotime($ticket['Created']);
            if ($timestamp !== false && $timestamp > 0) {
                $newLogin = strtolower(trim($customerUserId));
                if ($newLogin !== '') {
                    $newIndexKey = "znuny:closed_ticket:customer_user_index:{$newLogin}";
                    Redis::zadd($newIndexKey, $timestamp, $ticketId);
                    $this->extendIndexTtl($newIndexKey, $ttl + 86400);
                }

                $oldLogin = strtolower($oldCustomerUserId);
                if ($oldLogin !== '' && $oldLogin !== $newLogin) {
                    $oldIndexKey = "znuny:closed_ticket:customer_user_index:{$oldLogin}";
                    Redis::zrem($oldIndexKey, $ticketId);
                }
            }
        }
    }

    public function forgetTicket(int|string $ticketId): void
    {
        $ticketKey = "znuny:closed_ticket:ticket:{$ticketId}";
        $ticketData = Redis::get($ticketKey);

        Redis::del($ticketKey);

        if ($ticketData) {
            $ticket = json_decode($ticketData, true);
            if (! empty($ticket['Created'])) {
                $timestamp = strtotime($ticket['Created']);
                if ($timestamp !== false && $timestamp > 0) {
                    $date = date('Y-m-d', $timestamp);
                    $indexKey = "znuny:closed_ticket:index:{$date}";
                    Redis::zrem($indexKey, $ticketId);
                }
            }
            if (! empty($ticket['CustomerUserID'])) {
                $login = strtolower(trim((string) $ticket['CustomerUserID']));
                if ($login !== '') {
                    $userIndexKey = "znuny:closed_ticket:customer_user_index:{$login}";
                    Redis::zrem($userIndexKey, $ticketId);
                }
            }
        }
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

    public function validateMetadata(int $currentWindowDays): array
    {
        $metadata = $this->getMetadata();

        if (! $metadata) {
            return ['is_valid' => false, 'reason' => 'metadata_missing', 'metadata_status' => 'incomplete'];
        }

        if (($metadata['integrity_status'] ?? '') !== 'complete') {
            return ['is_valid' => false, 'reason' => 'metadata_incomplete', 'metadata_status' => 'incomplete'];
        }

        if (($metadata['window_days'] ?? null) !== $currentWindowDays) {
            return ['is_valid' => false, 'reason' => 'metadata_window_changed', 'metadata_status' => 'incomplete'];
        }

        if (empty($metadata['last_full_completed_at'])) {
            return ['is_valid' => false, 'reason' => 'metadata_missing_full_sync', 'metadata_status' => 'incomplete'];
        }

        if (empty($metadata['oldest_loaded_closed_at'])) {
            return ['is_valid' => false, 'reason' => 'metadata_missing_oldest', 'metadata_status' => 'incomplete'];
        }

        $boundaryTimestamp = time() - ($currentWindowDays * 86400);
        $oldestTimestamp = strtotime($metadata['oldest_loaded_closed_at']);

        if ($oldestTimestamp > $boundaryTimestamp) {
            return ['is_valid' => false, 'reason' => 'metadata_oldest_gap', 'metadata_status' => 'incomplete'];
        }

        return ['is_valid' => true, 'reason' => 'complete', 'metadata_status' => 'complete'];
    }
}
