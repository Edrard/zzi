<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ZnunyTicketArticleCacheService
{
    public function __construct(
        private readonly ZnunyClient $client
    ) {}

    public function getGeneration(): int
    {
        return (int) Cache::get('znuny:ticket:articles:generation', 1);
    }

    private function getCacheKey(int|string $ticketId): string
    {
        $gen = $this->getGeneration();

        return "znuny:ticket:articles:v{$gen}:{$ticketId}";
    }

    public function forgetAll(): void
    {
        $current = Cache::get('znuny:ticket:articles:generation');
        $timestamp = now()->timestamp;

        $next = is_numeric($current)
            ? max($timestamp, ((int) $current) + 1)
            : $timestamp;

        Cache::forever('znuny:ticket:articles:generation', $next);
    }

    private function getTtlMinutes(): int
    {
        $ttl = SettingsService::int('znuny_ticket_article_cache_ttl_minutes', 15);
        if ($ttl < 0) {
            return 15;
        }

        return $ttl;
    }

    private function rememberArticles(string $key, callable $callback): mixed
    {
        $ttl = $this->getTtlMinutes();

        if ($ttl === 0) {
            return $callback();
        }

        return Cache::remember($key, now()->addMinutes($ttl), $callback);
    }

    public function get(int|string $ticketId): array
    {
        $key = $this->getCacheKey($ticketId);

        try {
            return $this->rememberArticles($key, function () use ($ticketId) {
                return $this->client->getTicketArticles($ticketId);
            });
        } catch (Throwable $e) {
            return [
                [
                    'article_id' => null,
                    'article_number' => null,
                    'ticket_id' => $ticketId,
                    'subject' => __('zabbix_tickets.article_cache.error_subject'),
                    'body' => __('zabbix_tickets.article_cache.error_body'),
                    'from' => __('zabbix_tickets.article_cache.system_sender'),
                    'sender_type' => 'system',
                    'communication_channel' => null,
                    'is_visible_for_customer' => false,
                    'created_at' => null,
                ],
            ];
        }
    }

    public function forget(int|string $ticketId): void
    {
        Cache::forget($this->getCacheKey($ticketId));
    }

    public function refresh(int|string $ticketId): array
    {
        $this->forget($ticketId);

        return $this->get($ticketId);
    }
}
