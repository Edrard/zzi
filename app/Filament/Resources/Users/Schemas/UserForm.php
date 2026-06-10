<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('User details')
                    ->description('Manage user identity, role, and password.')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Select::make('role')
                            ->options([
                                'admin' => 'Admin',
                                'operator' => 'Operator',
                                'viewer' => 'Viewer',
                            ])
                            ->required()
                            ->disabled(fn (?User $record): bool => $record && $record->id === auth()->id())
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->disabled(fn (?User $record): bool => ($record && $record->id === auth()->id()) ||
                                ($record && $record->role === 'admin' && $record->is_active && User::where('role', 'admin')->where('is_active', true)->where('id', '!=', $record->id)->count() === 0)
                            )
                            ->columnSpanFull(),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->required(fn (string $context): bool => $context === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->confirmed()
                            ->maxLength(255),
                        TextInput::make('password_confirmation')
                            ->password()
                            ->revealable()
                            ->required(fn (string $context): bool => $context === 'create')
                            ->requiredWith('password')
                            ->dehydrated(false)
                            ->maxLength(255),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
