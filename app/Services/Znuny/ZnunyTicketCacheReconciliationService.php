<?php

namespace App\Services\Znuny;

use Illuminate\Support\Facades\Redis;

class ZnunyTicketCacheReconciliationService
{
    public function __construct(
        private readonly ZnunyTicketCacheService $activeCacheService,
        private readonly ClosedTicketCacheService $closedCacheService,
    ) {}

    public function reconcileKnownCustomerUserIdentity(
        string $requestedLogin,
        string $returnedLogin,
        string $returnedCustomerId,
        ?int $currentZnunyTicketId = null
    ): void {
        $requestedLogin = strtolower(trim($requestedLogin));
        $returnedLogin = trim($returnedLogin);
        $returnedCustomerId = trim($returnedCustomerId);

        if ($requestedLogin === '' || $returnedLogin === '' || $returnedCustomerId === '') {
            return;
        }

        [$activeIds, $closedIds] = $this->knownTicketIds($requestedLogin, $currentZnunyTicketId);

        foreach ($activeIds as $ticketId) {
            $ticket = $this->activeCacheService->getTicket($ticketId);
            if ($this->ticketMatchesLogin($ticket, $requestedLogin)) {
                $this->activeCacheService->mirrorConfirmedTicketIdentity(
                    $ticketId,
                    $returnedLogin,
                    $returnedCustomerId,
                );
            }
        }

        foreach ($closedIds as $ticketId) {
            $ticket = $this->closedCacheService->getTicket($ticketId);
            if ($this->ticketMatchesLogin($ticket, $requestedLogin)) {
                $this->closedCacheService->mirrorConfirmedTicketIdentity(
                    $ticketId,
                    $returnedLogin,
                    $returnedCustomerId,
                );
            }
        }
    }

    private function ticketMatchesLogin(mixed $ticket, string $login): bool
    {
        return is_array($ticket)
            && strtolower(trim((string) ($ticket['CustomerUserID'] ?? ''))) === $login;
    }

    private function knownTicketIds(string $login, ?int $currentZnunyTicketId): array
    {
        $activeIds = $this->getActiveTicketIds($login);
        $closedIds = $this->getClosedTicketIds($login);
        if ($currentZnunyTicketId) {
            $activeIds[] = (string) $currentZnunyTicketId;
            $closedIds[] = (string) $currentZnunyTicketId;
        }

        return [array_values(array_unique($activeIds)), array_values(array_unique($closedIds))];
    }

    private function getActiveTicketIds(string $login): array
    {
        $ids = Redis::zrange("znuny:index:customer_user:{$login}", 0, -1);

        return is_array($ids) ? $ids : [];
    }

    private function getClosedTicketIds(string $login): array
    {
        $ids = Redis::zrange("znuny:closed_ticket:customer_user_index:{$login}", 0, -1);

        return is_array($ids) ? $ids : [];
    }
}
