<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\UserLandingPageService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class MySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.my-settings';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'My Settings';

    public ?array $data = [];

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $service = app(UserLandingPageService::class);
        $options = $service->availableOptionsForUser($user);

        // Fallback default_landing_page to something valid if invalid
        $currentLandingPage = $user->default_landing_page;
        if (! array_key_exists($currentLandingPage, $options)) {
            $currentLandingPage = 'current-zabbix-problems';
        }

        $this->getForm('form')->fill([
            'default_landing_page' => $currentLandingPage,
            'show_current_problems_status_panel' => $user->show_current_problems_status_panel,
            'show_znuny_closed_ticket_status_panel' => $user->show_znuny_closed_ticket_status_panel,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        /** @var User $user */
        $user = auth()->user();
        $service = app(UserLandingPageService::class);

        return $schema
            ->schema([
                Section::make('Profile / Password')
                    ->description('Update your account password. Leave blank if you do not wish to change it.')
                    ->schema([
                        TextInput::make('current_password')
                            ->password()
                            ->label('Current password')
                            ->requiredWith('new_password')
                            ->currentPassword(),
                        TextInput::make('new_password')
                            ->password()
                            ->label('New password')
                            ->confirmed()
                            ->requiredWith('current_password'),
                        TextInput::make('new_password_confirmation')
                            ->password()
                            ->label('Confirm new password')
                            ->requiredWith('new_password'),
                    ]),

                Section::make('Startup / Default page')
                    ->description('Choose which page you land on after logging in.')
                    ->schema([
                        Select::make('default_landing_page')
                            ->label('Default landing page')
                            ->options($service->availableOptionsForUser($user))
                            ->required()
                            ->in(array_keys($service->availableOptionsForUser($user))),
                    ]),

                Section::make('Admin UI Preferences')
                    ->description('Toggle visibility of diagnostic panels.')
                    ->visible(fn () => $user->role === 'admin')
                    ->schema([
                        Toggle::make('show_current_problems_status_panel')
                            ->label('Show Current Problems polling status panel'),
                        Toggle::make('show_znuny_closed_ticket_status_panel')
                            ->label('Show Znuny closed ticket status panel'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->getForm('form')->getState();

        /** @var User $user */
        $user = auth()->user();

        $service = app(UserLandingPageService::class);

        // Update password if provided
        if (! empty($data['new_password'])) {
            $user->password = Hash::make($data['new_password']);
        }

        // Validate and update landing page
        $landingPage = $data['default_landing_page'] ?? 'current-zabbix-problems';
        if (! $service->isAllowedForUser($user, $landingPage)) {
            $landingPage = 'current-zabbix-problems';
        }
        $user->default_landing_page = $landingPage;

        // Update admin preferences
        if ($user->role === 'admin') {
            $user->show_current_problems_status_panel = $data['show_current_problems_status_panel'] ?? true;
            $user->show_znuny_closed_ticket_status_panel = $data['show_znuny_closed_ticket_status_panel'] ?? true;
        }

        $user->save();

        // Reset password fields
        $this->getForm('form')->fill([
            'default_landing_page' => $user->default_landing_page,
            'show_current_problems_status_panel' => $user->show_current_problems_status_panel,
            'show_znuny_closed_ticket_status_panel' => $user->show_znuny_closed_ticket_status_panel,
            'current_password' => null,
            'new_password' => null,
            'new_password_confirmation' => null,
        ]);

        Notification::make()
            ->title('Settings saved successfully')
            ->success()
            ->send();
    }
}
