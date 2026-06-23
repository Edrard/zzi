<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Redis;

class ZnunyTicketCacheService
{
    protected function isEnabled(): bool
    {
        return SettingsService::bool('znuny_ticket_workspace_enabled', true) ?? true;
    }

    protected function getTtl(): int
    {
        return SettingsService::int('znuny_ticket_cache_ttl_seconds', 900) ?? 900;
    }

    protected function getClosedTtl(): int
    {
        return SettingsService::int('znuny_ticket_cache_closed_ttl_seconds', 86400) ?? 86400;
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
        $ttl = $isClosed ? $this->getClosedTtl() : $this->getTtl();

        $key = "znuny:ticket:{$ticketId}";

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

        $ttl = $this->getClosedTtl();
        $key = "znuny:ticket:{$ticketId}";

        Redis::setex($key, max(1, $ttl), json_encode($ticket));
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
}
