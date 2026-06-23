<?php

namespace App\Filament\Resources\ZabbixTickets\Actions;

use App\Filament\Resources\ZabbixTickets\Schemas\ZabbixTicketInfolist;
use App\Filament\Support\ZnunyTicketManagementActions;
use App\Models\ZabbixTicket;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

class ZabbixTicketDetailsAction
{
    public static function make(string $name = 'viewTicket'): ViewAction
    {
        return ViewAction::make($name)
            ->slideOver()
            ->modalWidth(Width::FourExtraLarge)
            ->schema(fn (Schema $schema) => ZabbixTicketInfolist::configure($schema))
            ->mutateRecordDataUsing(function (ZabbixTicket $record, array $data) {
                $record->refresh();

                return $data;
            })
            ->extraModalFooterActions(fn (Action $action): array => [
                ZnunyTicketManagementActions::closeTicketAction('manual_close_ticket')
                    ->after(fn () => $action->cancel()),
                ZnunyTicketManagementActions::reopenTicketAction('reopen_ticket')
                    ->after(fn () => $action->cancel()),
            ]);
    }
}
