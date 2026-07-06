<?php

namespace App\Filament\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

class ZnunyTicketCreationSchema
{
    public static function schema(): array
    {
        return [
            Section::make('Ticket Details')->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    Select::make('queue')
                        ->label('Queue')
                        ->required()
                        ->options([]), // Will be dynamically populated later
                    Select::make('owner')
                        ->label('Owner')
                        ->options([]), // Will be dynamically populated later
                    Select::make('customer_user')
                        ->label('Customer User')
                        ->required()
                        ->options([]), // Will be dynamically populated later
                ]),
                TextInput::make('title')
                    ->label('Title')
                    ->required(),
                Textarea::make('body')
                    ->label('Article Body')
                    ->required()
                    ->rows(5),
            ]),

            Section::make('Advanced ticket options')
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2])->schema([
                        Select::make('priority')
                            ->label('Priority')
                            ->options([]), // Will be dynamically populated later
                        Select::make('state')
                            ->label('State')
                            ->options([]), // Will be dynamically populated later
                        Select::make('type')
                            ->label('Type')
                            ->options([]), // Will be dynamically populated later
                    ]),
                ])
                ->collapsible()
                ->collapsed(),
        ];
    }
}
