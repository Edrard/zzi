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
        return __('zabbix_problem_filters.resource.plural');
    }

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('zabbix_problem_filters.resource.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('zabbix_problem_filters.resource.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('zabbix_problem_filters.form.name'))
                    ->required()
                    ->maxLength(255),
                Toggle::make('enabled')
                    ->label(__('zabbix_problem_filters.form.enabled'))
                    ->default(true),
                Select::make('field')
                    ->label(__('zabbix_problem_filters.form.field'))
                    ->options([
                        'name' => __('zabbix_problem_filters.form.field_options.name'),
                        'host' => __('zabbix_problem_filters.form.field_options.host'),
                    ])
                    ->default('name')
                    ->required(),
                Select::make('match_type')
                    ->label(__('zabbix_problem_filters.form.match_type'))
                    ->options([
                        'contains' => __('zabbix_problem_filters.form.match_type_options.contains'),
                        'regex' => __('zabbix_problem_filters.form.match_type_options.regex'),
                    ])
                    ->default('regex')
                    ->required()
                    ->live(),
                Textarea::make('pattern')
                    ->label(__('zabbix_problem_filters.form.pattern'))
                    ->required()
                    ->helperText(fn (Get $get) => $get('match_type') === 'regex' ? __('zabbix_problem_filters.form.pattern_helper') : '')
                    ->rule(function (Get $get) {
                        return function (string $attribute, $value, Closure $fail) use ($get) {
                            if ($get('match_type') === 'regex') {
                                if (@preg_match($value, '') === false) {
                                    $fail(__('zabbix_problem_filters.form.pattern_invalid'));
                                }
                            }
                        };
                    }),
                Toggle::make('case_sensitive')
                    ->label(__('zabbix_problem_filters.form.case_sensitive'))
                    ->default(false),
                Textarea::make('description')
                    ->label(__('zabbix_problem_filters.form.description'))
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
                    ->label(__('zabbix_problem_filters.table.enabled'))
                    ->boolean(),
                TextColumn::make('name')
                    ->label(__('zabbix_problem_filters.table.name'))
                    ->searchable(),
                TextColumn::make('field')
                    ->label(__('zabbix_problem_filters.table.field'))
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'name' => __('zabbix_problem_filters.form.field_options.name'),
                        'host' => __('zabbix_problem_filters.form.field_options.host'),
                        default => $state,
                    }),
                TextColumn::make('match_type')
                    ->label(__('zabbix_problem_filters.table.match_type'))
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'contains' => __('zabbix_problem_filters.form.match_type_options.contains'),
                        'regex' => __('zabbix_problem_filters.form.match_type_options.regex'),
                        default => $state,
                    }),
                TextColumn::make('pattern')
                    ->label(__('zabbix_problem_filters.table.pattern'))
                    ->limit(30),
                TextColumn::make('updated_at')
                    ->label(__('zabbix_problem_filters.table.updated_at'))
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
