<?php

namespace App\Filament\Resources\ZabbixTickets\Actions;

use App\Models\ZabbixTicket;
use Filament\Actions\Action;
use Livewire\Component;

class ZabbixTicketDetailsAction
{
    public static function make(string $name = 'viewTicket'): Action
    {
        return Action::make($name)
            ->label('View Details')
            ->icon('heroicon-o-eye')
            ->action(function (?ZabbixTicket $record, array $arguments, Component $livewire) {
                $ticketId = $record?->znuny_ticket_id ?? null;

                if (! $ticketId && ! empty($arguments['ticket_id'])) {
                    $localTicket = ZabbixTicket::find($arguments['ticket_id']);
                    $ticketId = $localTicket?->znuny_ticket_id;
                }

                if ($ticketId) {
                    $livewire->dispatch('open-shared-ticket-modal', ticketId: $ticketId);
                }
            });
    }
}
