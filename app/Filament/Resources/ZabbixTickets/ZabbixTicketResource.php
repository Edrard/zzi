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
use Illuminate\Support\Facades\Lang;

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
        return __('zabbix_tickets.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('zabbix_tickets.navigation.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('zabbix_tickets.navigation.plural');
    }

    public static function translateZabbixStatus(array $presentation): array
    {
        $map = [
            'Flapping' => 'flapping',
            'Manual reopen candidate' => 'reopen_candidate',
            'Reopened' => 'reopened',
            'Closed' => 'closed',
            'Ready' => 'ready',
            'Waiting for close delay' => 'waiting',
            'Cache stale' => 'cache_stale',
            'Missing Zabbix identity' => 'identity_missing',
            'Active' => 'active',
            'Unknown' => 'unknown',
            'Active Problem' => 'active',
            'Resolved Problem' => 'ready',
        ];

        $key = $map[$presentation['label'] ?? ''] ?? null;
        if ($key) {
            $presentation['label'] = __('zabbix_tickets.zabbix_statuses.'.$key.'.label');
            if (isset($presentation['tooltip']) && $presentation['tooltip'] !== '') {
                // If it's a closed status without tooltip, keep it empty or use translation if present.
                // The translation file has empty string for closed tooltip, or we can just translate unconditionally if key exists.
                if (Lang::has('zabbix_tickets.zabbix_statuses.'.$key.'.tooltip')) {
                    $presentation['tooltip'] = __('zabbix_tickets.zabbix_statuses.'.$key.'.tooltip');
                }
            }
        }

        return $presentation;
    }

    public static function translateZnunyState(?string $state): ?string
    {
        if (empty($state)) {
            return $state;
        }

        $mapped = __('zabbix_tickets.znuny_states.'.strtolower($state));

        return $mapped !== 'zabbix_tickets.znuny_states.'.strtolower($state) ? $mapped : $state;
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
