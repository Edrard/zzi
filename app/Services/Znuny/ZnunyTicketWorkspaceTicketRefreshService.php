<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;

class ZnunyTicketWorkspaceTicketRefreshService
{
    public function __construct(
        private readonly ZnunyClient $client,
        private readonly ZnunyTicketArticleCacheService $articleCache,
        private readonly ZnunyTicketCacheService $activeTicketCache,
        private readonly ClosedTicketCacheService $closedTicketCache,
        private readonly ZnunyTicketWorkspaceCacheReader $cacheReader
    ) {}

    public function refreshTicket(int|string $ticketId): array
    {
        // 1. Fetch fresh ticket details
        $ticket = $this->client->getTicket($ticketId);

        // 2. Fetch fresh articles/notes and update cache
        $articles = $this->articleCache->refresh($ticketId);

        if (is_array($articles) && count($articles) > 0) {
            // Count valid articles (ignore error payloads)
            if (! empty($articles[0]['article_id'])) {
                $ticket['ArticleCount'] = count($articles);
                $lastArticle = end($articles);
                $ticket['LastArticleCreated'] = $lastArticle['created_at'] ?? null;
            }
        }

        // 3. Determine status
        $stateType = strtolower((string) ($ticket['StateType'] ?? ''));
        $isClosed = in_array($stateType, ['closed', 'merged'], true);
        $status = $isClosed ? 'closed' : 'open';

        // 4. Update the relevant Ticket Workspace caches
        if ($isClosed) {
            // Remove from active workspace cache
            $this->activeTicketCache->forgetTicket($ticketId);

            // Add to closed ticket cache
            $retentionDays = (int) SettingsService::int('znuny_closed_ticket_retention_days', 30);
            $this->closedTicketCache->upsertTicket($ticket, max(1, $retentionDays));
        } else {
            // Remove from closed ticket cache
            $this->closedTicketCache->forgetTicket($ticketId);

            // Add to active workspace cache
            $this->activeTicketCache->upsertTicket($ticket);
        }

        // 5. Return normalized payload and status
        $normalizedTicket = $this->cacheReader->normalizeSingleTicket($ticket);

        return [
            'status' => $status,
            'ticket' => $normalizedTicket,
        ];
    }
}
