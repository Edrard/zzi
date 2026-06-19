<?php

namespace App\Filament\Resources\ZabbixTickets\Pages;

use App\Filament\Resources\ZabbixTickets\ZabbixTicketResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListZabbixTickets extends ListRecords
{
    protected static string $resource = ZabbixTicketResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
