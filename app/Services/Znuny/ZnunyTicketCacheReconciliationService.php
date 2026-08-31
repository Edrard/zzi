<?php

namespace App\Services\Znuny;

use Illuminate\Support\Facades\Redis;

class ZnunyTicketCacheReconciliationService
{
    public function __construct(
        private readonly ZnunyTicketCacheService $activeCacheService,
        private readonly ClosedTicketCacheService $closedCacheService
    ) {}

    public function reconcileCustomerUser(
        string $oldLogin,
        string $authoritativeLogin,
        string $authoritativeCustomerId,
        ?int $currentZnunyTicketId = null
    ): void {
        $oldLogin = strtolower(trim($oldLogin));
        if ($oldLogin === '') {
            return;
        }

        $activeTicketIds = $this->getActiveTicketIds($oldLogin);
        $closedTicketIds = $this->getClosedTicketIds($oldLogin);

        if ($currentZnunyTicketId) {
            $activeTicketIds[] = (string) $currentZnunyTicketId;
            $closedTicketIds[] = (string) $currentZnunyTicketId;
        }

        $activeTicketIds = array_unique($activeTicketIds);
        $closedTicketIds = array_unique($closedTicketIds);

        foreach ($activeTicketIds as $ticketId) {
            $ticket = $this->activeCacheService->getTicket($ticketId);
            if ($ticket && strtolower(trim((string) ($ticket['CustomerUserID'] ?? ''))) === $oldLogin) {
                $this->activeCacheService->updateTicketIdentity($ticketId, $authoritativeLogin, $authoritativeCustomerId);
            }
        }

        foreach ($closedTicketIds as $ticketId) {
            $ticket = $this->closedCacheService->getTicket($ticketId);
            if ($ticket && strtolower(trim((string) ($ticket['CustomerUserID'] ?? ''))) === $oldLogin) {
                $this->closedCacheService->updateTicketIdentity($ticketId, $authoritativeLogin, $authoritativeCustomerId);
            }
        }
    }

    private function getActiveTicketIds(string $login): array
    {
        $key = "znuny:index:customer_user:{$login}";
        $ids = Redis::zrange($key, 0, -1);

        return is_array($ids) ? $ids : [];
    }

    private function getClosedTicketIds(string $login): array
    {
        $key = "znuny:closed_ticket:customer_user_index:{$login}";
        $ids = Redis::zrange($key, 0, -1);

        return is_array($ids) ? $ids : [];
    }
}
