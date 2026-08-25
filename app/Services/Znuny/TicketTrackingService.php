<?php

namespace App\Services\Znuny;

use App\Models\User;
use App\Models\ZnunyTicketSeenStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TicketTrackingService
{
    /**
     * Check if tracking is enabled for the given user.
     */
    public function isTrackingEnabled(User $user): bool
    {
        return $user->track_new_tickets && $user->ticket_tracking_since !== null;
    }

    /**
     * Get the list of seen ticket IDs among the provided IDs for the user.
     */
    public function getSeenTicketIds(User $user, array $ticketIds): array
    {
        if (empty($ticketIds)) {
            return [];
        }

        return ZnunyTicketSeenStatus::where('user_id', $user->id)
            ->whereIn('znuny_ticket_id', $ticketIds)
            ->pluck('znuny_ticket_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
    }

    /**
     * Mark a ticket as seen for the user if tracking is enabled.
     */
    public function markTicketAsSeen(User $user, int $ticketId): void
    {
        if (! $this->isTrackingEnabled($user)) {
            return;
        }

        ZnunyTicketSeenStatus::insertOrIgnore([
            'user_id' => $user->id,
            'znuny_ticket_id' => $ticketId,
            'opened_at' => Carbon::now(),
        ]);
    }

    /**
     * Check if a ticket is considered "new" for the user.
     */
    public function isTicketNew(User $user, array $ticketData, array $seenIds): bool
    {
        if (! $this->isTrackingEnabled($user)) {
            return false;
        }

        if (! empty($ticketData['is_linked_to_zabbix_problem'])) {
            return false;
        }

        $ticketId = $ticketData['TicketID'] ?? null;
        if (! $ticketId) {
            return false;
        }

        if (in_array((int) $ticketId, $seenIds, true)) {
            return false;
        }

        // Compare ticket creation time with user's tracking_since
        $createdAt = isset($ticketData['Created']) ? Carbon::parse($ticketData['Created']) : null;
        if ($createdAt && $user->ticket_tracking_since && $createdAt->isAfter($user->ticket_tracking_since)) {
            return true;
        }

        return false;
    }
}
