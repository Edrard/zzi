<?php

namespace App\Filament\Resources\ZabbixTickets\Actions;

use App\Filament\Resources\ZabbixTickets\Schemas\ZabbixTicketInfolist;
use App\Filament\Support\TicketDetailsPayload;
use App\Filament\Support\ZnunyTicketManagementActions;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

class ZabbixTicketDetailsAction
{
    public static function make(string $name = 'viewTicket'): Action
    {
        return Action::make($name)
            ->slideOver()
            ->modalWidth(Width::FourExtraLarge)
            ->modalHeading(fn (array $arguments, $record = null) => TicketDetailsPayload::fromRecord($record, $arguments)->title ?? 'Ticket Details')
            ->modalSubmitAction(false)
            ->modalCancelAction(false)
            ->schema(fn (Schema $schema) => ZabbixTicketInfolist::configure($schema))
            ->extraModalFooterActions(fn (Action $action): array => [
                ZnunyTicketManagementActions::closeTicketAction('manual_close_ticket')
                    ->after(fn () => $action->cancel()),
                ZnunyTicketManagementActions::reopenTicketAction('reopen_ticket')
                    ->after(fn () => $action->cancel()),
                Action::make('cancel')
                    ->label('Close')
                    ->close()
                    ->color('gray'),
                ZnunyTicketManagementActions::openInZnunyAction('open_ticket')
                    ->extraAttributes(['class' => 'zbx-open-ticket-footer-action']),
            ]);
    }
}
