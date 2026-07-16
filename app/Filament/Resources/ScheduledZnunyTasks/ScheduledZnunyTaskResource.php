<?php

namespace App\Filament\Resources\ScheduledZnunyTasks;

use App\Filament\Resources\ScheduledZnunyTasks\Pages\CreateScheduledZnunyTask;
use App\Filament\Resources\ScheduledZnunyTasks\Pages\EditScheduledZnunyTask;
use App\Filament\Resources\ScheduledZnunyTasks\Pages\ListScheduledZnunyTasks;
use App\Filament\Resources\ScheduledZnunyTasks\Schemas\ScheduledZnunyTaskForm;
use App\Filament\Resources\ScheduledZnunyTasks\Schemas\ScheduledZnunyTaskInfolist;
use App\Filament\Resources\ScheduledZnunyTasks\Tables\ScheduledZnunyTasksTable;
use App\Models\ScheduledZnunyTask;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ScheduledZnunyTaskResource extends Resource
{
    protected static ?string $model = ScheduledZnunyTask::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|\UnitEnum|null $navigationGroup = 'Znuny';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->role === 'admin';
    }

    public static function form(Schema $schema): Schema
    {
        return ScheduledZnunyTaskForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ScheduledZnunyTaskInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScheduledZnunyTasksTable::configure($table);
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
            'index' => ListScheduledZnunyTasks::route('/'),
            'create' => CreateScheduledZnunyTask::route('/create'),
            'edit' => EditScheduledZnunyTask::route('/{record}'),
        ];
    }
}
