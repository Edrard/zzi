<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\AuditLogger;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected string $view = 'filament.pages.settings';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()->role === 'admin';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save settings')
                ->action('save'),
        ];
    }

    public function mount(): void
    {
        $settings = Setting::query()->orderBy('key')->get();
        $initialData = [];

        foreach ($settings as $setting) {
            if (in_array($setting->key, ['zabbix_api_token', 'znuny_password'])) {
                $initialData[$setting->key] = '';

                continue;
            }

            if ($setting->type === 'boolean') {
                $initialData[$setting->key] = $setting->value === 'true';
            } else {
                $initialData[$setting->key] = $setting->value;
            }
        }

        $this->form->fill($initialData);
    }

    public function form(Schema $schema): Schema
    {
        $settings = Setting::query()->orderBy('key')->get();

        $groups = [
            'General' => [],
            'Retention' => [],
            'Zabbix' => [],
            'Znuny' => [],
            'Automation' => [],
            'Other' => [],
        ];

        foreach ($settings as $setting) {
            $label = Str::title(str_replace('_', ' ', $setting->key));
            $component = null;

            if ($setting->type === 'boolean') {
                $component = Toggle::make($setting->key)
                    ->label($label)
                    ->helperText($setting->description)
                    ->required();
            } elseif ($setting->type === 'integer') {
                $min = 0;
                if ($setting->key === 'cleanup_batch_size') {
                    $min = 1;
                }

                $component = TextInput::make($setting->key)
                    ->label($label)
                    ->helperText($setting->description)
                    ->numeric()
                    ->integer()
                    ->minValue($min)
                    ->required();
            } elseif ($setting->type === 'json') {
                $component = Textarea::make($setting->key)
                    ->label($label)
                    ->helperText($setting->description)
                    ->rule('json')
                    ->required();
            } else {
                $input = TextInput::make($setting->key)
                    ->label($label)
                    ->helperText($setting->description)
                    ->required();

                if (in_array($setting->key, ['zabbix_api_token', 'znuny_password'])) {
                    $input->password()
                        ->revealable()
                        ->placeholder('Leave empty to keep current password')
                        ->required(false);
                }

                $component = $input;
            }

            if (in_array($setting->key, ['cleanup_enabled', 'cleanup_batch_size'])) {
                $groups['General'][] = $component;
            } elseif (in_array($setting->key, ['retention_action_logs_days', 'retention_closed_tickets_days', 'retention_failed_jobs_days', 'retention_resolved_days', 'retention_statistics_days'])) {
                $groups['Retention'][] = $component;
            } elseif (in_array($setting->key, ['zabbix_api_url', 'zabbix_api_token', 'zabbix_api_timeout', 'zabbix_api_verify_ssl', 'zabbix_poll_interval_minutes', 'zabbix_problem_cache_ttl_minutes', 'zabbix_problem_limit', 'zabbix_exclude_suppressed_problems'])) {
                $groups['Zabbix'][] = $component;
            } elseif (str_starts_with($setting->key, 'znuny_')) {
                $groups['Znuny'][$setting->key] = $component;
            } elseif (in_array($setting->key, ['default_close_delay_hours', 'default_reopen_window_hours'])) {
                $groups['Automation'][] = $component;
            } else {
                $groups['Other'][] = $component;
            }
        }

        if (! empty($groups['Znuny'])) {
            $z = $groups['Znuny'];

            $groups['Znuny'] = [
                Section::make('Credentials')
                    ->schema(array_filter([
                        $z['znuny_username'] ?? null,
                        $z['znuny_password'] ?? null,
                    ]))->columns(1),

                Section::make('Endpoints')
                    ->schema(array_filter([
                        $z['znuny_api_url'] ?? null,
                        $z['znuny_web_url'] ?? null,
                        $z['znuny_ticket_url_template'] ?? null,
                    ]))->columns(1),

                Section::make('Connection')
                    ->schema(array_filter([
                        $z['znuny_api_verify_ssl'] ?? null,
                        $z['znuny_api_timeout'] ?? null,
                    ]))->columns(1),
            ];

            $knownKeys = ['znuny_username', 'znuny_password', 'znuny_api_url', 'znuny_web_url', 'znuny_ticket_url_template', 'znuny_api_verify_ssl', 'znuny_api_timeout'];
            $unknownComponents = array_diff_key($z, array_flip($knownKeys));

            if (! empty($unknownComponents)) {
                $groups['Znuny'][] = Section::make('Other')
                    ->schema(array_values($unknownComponents))->columns(1);
            }
        }

        $tabs = [];
        foreach ($groups as $groupName => $components) {
            if (! empty($components)) {
                $tabs[] = Tab::make($groupName)
                    ->schema($components)
                    ->columns(1);
            }
        }

        $formComponents = [
            Tabs::make('SettingsTabs')
                ->tabs($tabs),
            Actions::make([
                Action::make('saveBottom')
                    ->label('Save settings')
                    ->action('save'),
            ])->alignEnd(),
        ];

        return $schema
            ->components($formComponents)
            ->statePath('data');
    }

    public function save(): void
    {
        if (auth()->user()->role !== 'admin') {
            abort(403, 'Only admins can modify settings.');
        }

        $data = $this->form->getState();
        $settings = Setting::query()->orderBy('key')->get();
        $changedSettings = [];

        foreach ($settings as $setting) {
            if (array_key_exists($setting->key, $data)) {
                $newValue = $data[$setting->key];

                if ($setting->type === 'boolean') {
                    $newValue = $newValue ? 'true' : 'false';
                } else {
                    $newValue = (string) $newValue;
                }

                // Skip updating if a password field is submitted as empty
                if ($newValue === '' && in_array($setting->key, ['zabbix_api_token', 'znuny_password'])) {
                    continue;
                }

                if ($setting->value !== $newValue) {
                    $changedSettings[] = [
                        'key' => $setting->key,
                        'old_value' => $setting->value,
                        'new_value' => $newValue,
                    ];
                    $setting->update(['value' => $newValue]);
                }
            }
        }

        if (! empty($changedSettings)) {
            $sensitiveKeywords = ['token', 'password', 'secret', 'api_key', 'session'];

            $sanitizedChanges = array_map(function ($change) use ($sensitiveKeywords) {
                $isSensitive = false;
                foreach ($sensitiveKeywords as $keyword) {
                    if (str_contains(strtolower($change['key']), $keyword)) {
                        $isSensitive = true;
                        break;
                    }
                }

                if ($isSensitive) {
                    $change['old_value'] = '[redacted]';
                    $change['new_value'] = '[redacted]';
                }

                return $change;
            }, $changedSettings);

            AuditLogger::log(
                action: 'settings.updated',
                entityType: 'settings',
                entityId: null,
                context: ['changes' => $sanitizedChanges]
            );
        }

        Notification::make()
            ->title('Settings saved successfully.')
            ->success()
            ->send();
    }
}
