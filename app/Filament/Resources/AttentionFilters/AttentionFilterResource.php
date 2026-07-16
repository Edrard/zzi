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
        return __('navigation.resources.attention_filters.plural');
    }

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('navigation.resources.attention_filters.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.resources.attention_filters.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Toggle::make('enabled')
                    ->default(true),
                Textarea::make('pattern')
                    ->required()
                    ->helperText('Example: /^Zabbix proxy.*$/ (must include delimiters)')
                    ->rule(function () {
                        return function (string $attribute, $value, Closure $fail) {
                            if (@preg_match($value, '') === false) {
                                $fail('Invalid regular expression. Ensure you include delimiters (e.g. /pattern/).');
                            }
                        };
                    }),
                Textarea::make('description')
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
                    ->boolean(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('pattern')
                    ->limit(50),
                TextColumn::make('updated_at')
                    ->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatDateTime($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => in_array(auth()->user()->role, ['admin', 'operator'], true)),
                DeleteAction::make()
                    ->visible(fn () => in_array(auth()->user()->role, ['admin', 'operator'], true)),
            ])
            ->toolbarActions([
                // No bulk delete actions
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAttentionFilters::route('/'),
        ];
    }
}
