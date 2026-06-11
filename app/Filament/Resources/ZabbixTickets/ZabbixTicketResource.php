<?php

namespace App\Filament\Resources\ZabbixTickets;

use App\Filament\Resources\ZabbixTickets\Pages\ListZabbixTickets;
use App\Filament\Resources\ZabbixTickets\Pages\ViewZabbixTicket;
use App\Filament\Resources\ZabbixTickets\Schemas\ZabbixTicketInfolist;
use App\Filament\Resources\ZabbixTickets\Tables\ZabbixTicketsTable;
use App\Models\ZabbixTicket;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ZabbixTicketResource extends Resource
{
    protected static ?string $model = ZabbixTicket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|\UnitEnum|null $navigationGroup = 'Znuny';

    protected static ?string $navigationLabel = 'Znuny Tickets';

    protected static ?string $modelLabel = 'Znuny Ticket';

    protected static ?string $pluralModelLabel = 'Znuny Tickets';

    public static function infolist(Schema $schema): Schema
    {
        return ZabbixTicketInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ZabbixTicketsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListZabbixTickets::route('/'),
            'view' => ViewZabbixTicket::route('/{record}'),
        ];
    }
}
