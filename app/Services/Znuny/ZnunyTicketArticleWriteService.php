<?php

namespace App\Services\Znuny;

use Illuminate\Support\Facades\Log;
use Throwable;

class ZnunyTicketArticleWriteService
{
    protected ZnunyClient $client;

    protected ZnunyTicketArticleCacheService $cacheService;

    public function __construct(
        ZnunyClient $client,
        ZnunyTicketArticleCacheService $cacheService
    ) {
        $this->client = $client;
        $this->cacheService = $cacheService;
    }

    /**
     * Create an internal note or a customer-visible article on an existing ticket.
     *
     * @return array{
     *   success: bool,
     *   article_id?: int|string,
     *   ticket_id?: int|string,
     *   ticket_number?: string,
     *   warnings: array<int, string>,
     *   errors: array<int, string>,
     *   raw: array<string, mixed>
     * }
     */
    public function createTicketArticle(int|string $ticketId, string $subject, string $body, bool $visibleForCustomer): array
    {
        try {
            $response = $this->client->createTicketArticle($ticketId, $subject, $body, $visibleForCustomer);

            if ($response['success']) {
                $this->cacheService->forget($ticketId);
                // Optionally preload it right away
                // $this->cacheService->get($ticketId);
            }

            return $response;
        } catch (Throwable $e) {
            Log::error('Failed to create ticket article/note: '.$e->getMessage(), [
                'ticket_id' => $ticketId,
                'subject' => $subject,
                'visible_for_customer' => $visibleForCustomer,
                'exception' => $e,
            ]);

            return [
                'success' => false,
                'warnings' => [],
                'errors' => [$e->getMessage()],
                'raw' => [],
            ];
        }
    }
}
