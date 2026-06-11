<?php

namespace App\Filament\Resources\ZabbixTickets\Pages;

use App\Filament\Resources\ZabbixTickets\ZabbixTicketResource;
use Filament\Resources\Pages\ViewRecord;

class ViewZabbixTicket extends ViewRecord
{
    protected static string $resource = ZabbixTicketResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
