<?php

namespace App\Filament\Resources\ZabbixProblemFilters;

use App\Filament\Resources\ZabbixProblemFilters\Pages\ManageZabbixProblemFilters;
use App\Models\ZabbixProblemFilter;
use App\Services\Support\DateTimeDisplayService;
use App\Support\Pagination\PaginationSettings;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ZabbixProblemFilterResource extends Resource
{
    protected static ?string $model = ZabbixProblemFilter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.zabbix');
    }

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.ignore_filters.navigation_label');
    }

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('navigation.resources.ignore_filters.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.resources.ignore_filters.plural');
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
                Select::make('field')
                    ->options([
                        'name' => 'Problem name',
                        'host' => 'Host name',
                    ])
                    ->default('name')
                    ->required(),
                Select::make('match_type')
                    ->options([
                        'contains' => 'Contains',
                        'regex' => 'Regex',
                    ])
                    ->default('regex')
                    ->required()
                    ->live(),
                Textarea::make('pattern')
                    ->required()
                    ->helperText(fn (Get $get) => $get('match_type') === 'regex' ? 'Example: /^Zabbix proxy.*$/ (must include delimiters)' : '')
                    ->rule(function (Get $get) {
                        return function (string $attribute, $value, Closure $fail) use ($get) {
                            if ($get('match_type') === 'regex') {
                                if (@preg_match($value, '') === false) {
                                    $fail('Invalid regular expression. Ensure you include delimiters (e.g. /pattern/).');
                                }
                            }
                        };
                    }),
                Toggle::make('case_sensitive')
                    ->default(false),
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
                TextColumn::make('field'),
                TextColumn::make('match_type'),
                TextColumn::make('pattern')
                    ->limit(30),
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
            'index' => ManageZabbixProblemFilters::route('/'),
        ];
    }
}
