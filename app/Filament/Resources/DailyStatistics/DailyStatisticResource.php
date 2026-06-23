<?php

namespace App\Filament\Resources\DailyStatistics;

use App\Filament\Resources\DailyStatistics\Pages\ListDailyStatistics;
use App\Filament\Resources\DailyStatistics\Pages\ViewDailyStatistic;
use App\Filament\Resources\DailyStatistics\Schemas\DailyStatisticInfolist;
use App\Filament\Resources\DailyStatistics\Tables\DailyStatisticsTable;
use App\Models\DailyStatistic;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DailyStatisticResource extends Resource
{
    protected static ?string $model = DailyStatistic::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Reports / Statistics';

    protected static ?string $navigationLabel = 'Daily Statistics';

    protected static ?string $pluralLabel = 'Daily Statistics';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return DailyStatisticInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DailyStatisticsTable::configure($table);
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
            'index' => ListDailyStatistics::route('/'),
            'view' => ViewDailyStatistic::route('/{record}'),
        ];
    }
}
