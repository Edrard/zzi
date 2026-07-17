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
                Section::make(__('users.form.sections.user_details.heading'))
                    ->description(__('users.form.sections.user_details.description'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('users.form.fields.name.label'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label(__('users.form.fields.email.label'))
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Select::make('role')
                            ->label(__('users.form.fields.role.label'))
                            ->options([
                                'admin' => __('users.roles.admin'),
                                'operator' => __('users.roles.operator'),
                                'viewer' => __('users.roles.viewer'),
                            ])
                            ->required()
                            ->disabled(fn (?User $record): bool => $record && $record->id === auth()->id())
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label(__('users.form.fields.is_active.label'))
                            ->default(true)
                            ->disabled(fn (?User $record): bool => ($record && $record->id === auth()->id()) ||
                                ($record && $record->role === 'admin' && $record->is_active && User::where('role', 'admin')->where('is_active', true)->where('id', '!=', $record->id)->count() === 0)
                            )
                            ->columnSpanFull(),
                        TextInput::make('password')
                            ->label(__('users.form.fields.password.label'))
                            ->password()
                            ->revealable()
                            ->required(fn (string $context): bool => $context === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->confirmed()
                            ->maxLength(255),
                        TextInput::make('password_confirmation')
                            ->label(__('users.form.fields.password_confirmation.label'))
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
