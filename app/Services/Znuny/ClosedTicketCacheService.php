<?php

namespace App\Services\Znuny;

use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use Illuminate\Support\Facades\Redis;

class ClosedTicketCacheService
{
    public function __construct(
        private readonly ZnunyLookupCacheReadService $lookupCache
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

        $customerId = (string) ($ticket['CustomerID'] ?? '');
        $ticket['customer_user_registered'] = $this->lookupCache->hasCustomerCompany($customerId);

        Redis::setex($ticketKey, $retentionSeconds, json_encode($ticket));

        $indexKey = "znuny:closed_ticket:index:{$date}";
        Redis::zadd($indexKey, $timestamp, $ticketId);
        Redis::expire($indexKey, $retentionSeconds);
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
