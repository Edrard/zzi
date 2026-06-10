<?php

namespace App\Filament\Resources\ZabbixProblemFilters;

use App\Filament\Resources\ZabbixProblemFilters\Pages\ManageZabbixProblemFilters;
use App\Models\ZabbixProblemFilter;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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

    protected static string|\UnitEnum|null $navigationGroup = 'Automation';

    protected static ?string $navigationLabel = 'Problem Filters';

    protected static ?string $recordTitleAttribute = 'name';

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
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
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
