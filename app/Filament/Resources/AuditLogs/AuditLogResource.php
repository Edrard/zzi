<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Filament\Resources\AuditLogs\Schemas\AuditLogInfolist;
use App\Filament\Resources\AuditLogs\Tables\AuditLogsTable;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.administration');
    }

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return __('audit_logs.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('audit_logs.model.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('audit_logs.model.plural_label');
    }

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

    public static function actionLabel(?string $action): ?string
    {
        if ($action === null) {
            return null;
        }

        $key = "audit_logs.actions.{$action}";
        $translated = __($key);

        return $translated === $key ? $action : $translated;
    }

    public static function entityTypeLabel(?string $entityType): ?string
    {
        if ($entityType === null) {
            return null;
        }

        if ($entityType === '') {
            return '';
        }

        $type = Str::snake(class_basename($entityType));
        $key = "audit_logs.entity_types.{$type}";
        $translated = __($key);

        return $translated === $key ? $entityType : $translated;
    }

    public static function actorLabel(?string $name): string
    {
        if (empty($name)) {
            return __('audit_logs.entity_types.system');
        }

        return $name;
    }

    public static function infolist(Schema $schema): Schema
    {
        return AuditLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AuditLogsTable::configure($table);
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
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }
}
