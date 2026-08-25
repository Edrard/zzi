<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Support\ApplicationLocaleService;
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
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Hash;

class MySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.my-settings';

    protected static bool $shouldRegisterNavigation = false;

    public function getTitle(): string|Htmlable
    {
        return __('navigation.pages.my_settings');
    }

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
            'show_scheduled_tasks_status_panel' => $user->show_scheduled_tasks_status_panel,
            'ui_locale' => $user->ui_locale ?? '__system__',
            'track_new_tickets' => $user->track_new_tickets,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        /** @var User $user */
        $user = auth()->user();
        $service = app(UserLandingPageService::class);

        return $schema
            ->schema([
                Section::make('profilePassword')
                    ->heading(__('settings.my_settings.sections.profile_password.title'))
                    ->description(__('settings.my_settings.sections.profile_password.description'))
                    ->schema([
                        TextInput::make('current_password')
                            ->password()
                            ->label(__('settings.my_settings.fields.current_password.label'))
                            ->requiredWith('new_password')
                            ->currentPassword(),
                        TextInput::make('new_password')
                            ->password()
                            ->label(__('settings.my_settings.fields.new_password.label'))
                            ->confirmed()
                            ->requiredWith('current_password'),
                        TextInput::make('new_password_confirmation')
                            ->password()
                            ->label(__('settings.my_settings.fields.new_password_confirmation.label'))
                            ->requiredWith('new_password'),
                    ]),

                Section::make('personalization')
                    ->heading(__('settings.my_settings.sections.personalization.title'))
                    ->description(__('settings.my_settings.sections.personalization.description'))
                    ->schema([
                        Toggle::make('track_new_tickets')
                            ->label(__('settings.my_settings.fields.track_new_tickets.label'))
                            ->helperText(__('settings.my_settings.fields.track_new_tickets.helper_text')),
                        Select::make('ui_locale')
                            ->label(__('settings.my_settings.ui_locale.label'))
                            ->helperText(__('settings.my_settings.ui_locale.helper_text'))
                            ->options(array_merge(
                                ['__system__' => __('settings.my_settings.ui_locale.system_default')],
                                app(ApplicationLocaleService::class)->options()
                            ))
                            ->required()
                            ->in(fn () => array_merge(
                                ['__system__'],
                                app(ApplicationLocaleService::class)->supportedLocales()
                            ))
                            ->native(false),
                    ]),

                Section::make('startup')
                    ->heading(__('settings.my_settings.sections.startup.title'))
                    ->description(__('settings.my_settings.sections.startup.description'))
                    ->schema([
                        Select::make('default_landing_page')
                            ->label(__('settings.my_settings.fields.default_landing_page.label'))
                            ->options($service->availableOptionsForUser($user))
                            ->required()
                            ->in(array_keys($service->availableOptionsForUser($user))),
                    ]),

                Section::make('adminUiPreferences')
                    ->heading(__('settings.my_settings.sections.admin_ui_preferences.title'))
                    ->description(__('settings.my_settings.sections.admin_ui_preferences.description'))
                    ->visible(fn () => $user->role === 'admin')
                    ->schema([
                        Toggle::make('show_current_problems_status_panel')
                            ->label(__('settings.my_settings.fields.show_current_problems_status_panel.label')),
                        Toggle::make('show_znuny_closed_ticket_status_panel')
                            ->label(__('settings.my_settings.fields.show_znuny_closed_ticket_status_panel.label')),
                        Toggle::make('show_scheduled_tasks_status_panel')
                            ->label(__('settings.my_settings.fields.show_scheduled_tasks_status_panel.label')),
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
        $localeService = app(ApplicationLocaleService::class);

        $previousLocale = $localeService->resolve($user);

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
            $user->show_scheduled_tasks_status_panel = $data['show_scheduled_tasks_status_panel'] ?? true;
        }

        if (isset($data['ui_locale'])) {
            $user->ui_locale = $data['ui_locale'] === '__system__' ? null : $data['ui_locale'];
        }

        $wasTracking = $user->track_new_tickets;
        $isTrackingNow = $data['track_new_tickets'] ?? false;
        $user->track_new_tickets = $isTrackingNow;

        if ($isTrackingNow && ! $wasTracking) {
            $user->ticket_tracking_since = \Illuminate\Support\Carbon::now();
        }

        $user->save();
        $user->refresh();

        $newLocale = $localeService->resolve($user);
        $localeService->apply($newLocale);

        // Reset password fields
        $this->getForm('form')->fill([
            'default_landing_page' => $user->default_landing_page,
            'show_current_problems_status_panel' => $user->show_current_problems_status_panel,
            'show_znuny_closed_ticket_status_panel' => $user->show_znuny_closed_ticket_status_panel,
            'show_scheduled_tasks_status_panel' => $user->show_scheduled_tasks_status_panel,
            'ui_locale' => $user->ui_locale ?? '__system__',
            'track_new_tickets' => $user->track_new_tickets,
            'current_password' => null,
            'new_password' => null,
            'new_password_confirmation' => null,
        ]);

        Notification::make()
            ->title(__('settings.my_settings.notifications.saved.title'))
            ->success()
            ->send();

        if ($newLocale !== $previousLocale) {
            $this->redirect(static::getUrl(), navigate: false);
        }
    }
}
