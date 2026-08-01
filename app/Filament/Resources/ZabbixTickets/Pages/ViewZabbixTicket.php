<?php

namespace App\Filament\Resources\ZabbixTickets\Pages;

use App\Filament\Resources\ZabbixTickets\ZabbixTicketResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewZabbixTicket extends ViewRecord
{
    protected static string $resource = ZabbixTicketResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('zabbix_tickets.navigation.singular');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
