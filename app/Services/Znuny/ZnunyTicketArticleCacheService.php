<?php

namespace App\Services\Znuny;

use Illuminate\Support\Facades\Cache;
use Throwable;

class ZnunyTicketArticleCacheService
{
    private const TTL = 15 * 60; // 15 minutes

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
        $gen = $this->getGeneration();
        Cache::forever('znuny:ticket:articles:generation', $gen + 1);
    }

    public function get(int|string $ticketId): array
    {
        $key = $this->getCacheKey($ticketId);

        try {
            return Cache::remember($key, self::TTL, function () use ($ticketId) {
                return $this->client->getTicketArticles($ticketId);
            });
        } catch (Throwable $e) {
            return [
                [
                    'article_id' => null,
                    'article_number' => null,
                    'ticket_id' => $ticketId,
                    'subject' => 'Error loading articles',
                    'body' => 'Could not fetch articles from Znuny.',
                    'from' => 'System',
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
