<?php

namespace App\Filament\Resources\ZabbixTickets;

use App\Filament\Resources\ZabbixTickets\Pages\ListZabbixTickets;
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

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.znuny');
    }

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.linked_tickets.plural');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.resources.linked_tickets.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.resources.linked_tickets.plural');
    }

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
        ];
    }
}
