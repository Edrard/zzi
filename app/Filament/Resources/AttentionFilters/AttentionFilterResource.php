<?php

namespace App\Filament\Resources\AttentionFilters;

use App\Filament\Resources\AttentionFilters\Pages\ManageAttentionFilters;
use App\Models\AttentionFilter;
use App\Services\Support\DateTimeDisplayService;
use App\Support\Pagination\PaginationSettings;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AttentionFilterResource extends Resource
{
    protected static ?string $model = AttentionFilter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationCircle;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.zabbix');
    }

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('attention_filters.resource.plural');
    }

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('attention_filters.resource.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('attention_filters.resource.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('attention_filters.form.name'))
                    ->helperText(__('attention_filters.form.name_helper'))
                    ->required()
                    ->maxLength(255),
                Toggle::make('enabled')
                    ->label(__('attention_filters.form.enabled'))
                    ->default(true),
                Textarea::make('pattern')
                    ->label(__('attention_filters.form.pattern'))
                    ->required()
                    ->helperText(__('attention_filters.form.pattern_helper'))
                    ->rule(function () {
                        return function (string $attribute, $value, Closure $fail) {
                            if (@preg_match($value, '') === false) {
                                $fail(__('attention_filters.form.pattern_invalid'));
                            }
                        };
                    }),
                Textarea::make('description')
                    ->label(__('attention_filters.form.description'))
                    ->maxLength(65535)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultPaginationPageOption(app(PaginationSettings::class)->defaultPerPage())
            ->paginationPageOptions(app(PaginationSettings::class)->perPageOptions())
            ->columns([
                IconColumn::make('enabled')
                    ->label(__('attention_filters.table.enabled'))
                    ->boolean(),
                TextColumn::make('name')
                    ->label(__('attention_filters.table.name'))
                    ->searchable(),
                TextColumn::make('pattern')
                    ->label(__('attention_filters.table.pattern'))
                    ->limit(50),
                TextColumn::make('updated_at')
                    ->label(__('attention_filters.table.updated_at'))
                    ->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatDateTime($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading(__('attention_filters.actions.edit.heading'))
                    ->successNotificationTitle(__('attention_filters.notifications.updated'))
                    ->failureNotificationTitle(__('attention_filters.notifications.save_failed'))
                    ->visible(fn () => in_array(auth()->user()->role, ['admin', 'operator'], true)),
                DeleteAction::make()
                    ->modalHeading(__('attention_filters.actions.delete.heading'))
                    ->modalDescription(__('attention_filters.actions.delete.description'))
                    ->successNotificationTitle(__('attention_filters.notifications.deleted'))
                    ->failureNotificationTitle(__('attention_filters.notifications.delete_failed'))
                    ->visible(fn () => in_array(auth()->user()->role, ['admin', 'operator'], true)),
            ])
            ->toolbarActions([
                // No bulk delete actions
            ])
            ->emptyStateHeading(__('attention_filters.empty_states.no_records'))
            ->emptyStateDescription(__('attention_filters.empty_states.no_records_description'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAttentionFilters::route('/'),
        ];
    }
}
