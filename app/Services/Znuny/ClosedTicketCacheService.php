<?php

namespace App\Services\Znuny;

use Illuminate\Support\Facades\Redis;

class ClosedTicketCacheService
{
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

    public function upsertTicket(array $ticket, int $retentionDays): void
    {
        if (empty($ticket['TicketID'])) {
            return;
        }

        $ticketId = $ticket['TicketID'];
        $changedAt = $ticket['Changed'] ?? $ticket['Created'] ?? date('Y-m-d H:i:s');
        $timestamp = strtotime($changedAt);
        $date = date('Y-m-d', $timestamp);
        $retentionSeconds = $retentionDays * 86400;

        $ticketKey = "znuny:closed_ticket:ticket:{$ticketId}";
        Redis::setex($ticketKey, $retentionSeconds, json_encode($ticket));

        $indexKey = "znuny:closed_ticket:index:{$date}";
        Redis::zadd($indexKey, $timestamp, $ticketId);
        Redis::expire($indexKey, $retentionSeconds);
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
