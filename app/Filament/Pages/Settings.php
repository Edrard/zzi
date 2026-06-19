<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\SettingsAuditLogService;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyDefaultAgentSchemaBuilder;
use App\Services\Znuny\ZnunyDefaultAgentSettingsService;
use App\Services\Znuny\ZnunyQueueHostMappingSchemaBuilder;
use App\Services\Znuny\ZnunyQueueHostMappingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
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

    public function testZnunyConnectionAction(): void
    {
        $data = $this->form->getRawState();
        $apiUrl = $data['znuny_api_url'] ?? '';
        $username = $data['znuny_username'] ?? '';
        $password = $data['znuny_password'] ?? '';

        $passwordSource = 'form';
        if (empty($password)) {
            $password = SettingsService::string('znuny_password', '');
            $passwordSource = empty($password) ? 'missing' : 'saved';
        }

        $errorResult = null;
        if (empty($apiUrl)) {
            $errorResult = 'Znuny API URL is required.';
        } elseif (empty($username)) {
            $errorResult = 'Znuny username is required.';
        } elseif (empty($password)) {
            $errorResult = 'Znuny password is required.';
        }

        if ($errorResult !== null) {
            AuditLogger::log(
                action: 'settings.znuny_connection_tested',
                entityType: 'settings',
                entityId: null,
                context: [
                    'source' => 'form_state',
                    'password_source' => $passwordSource,
                    'status' => 'failed',
                    'checks' => [],
                    'counts' => [],
                    'warnings' => [],
                    'errors' => [$errorResult],
                ]
            );

            Notification::make()
                ->title('Znuny API Connection Failed')
                ->body(new HtmlString('<strong>Errors:</strong><br>❌ '.htmlspecialchars($errorResult).'<br>'))
                ->color('danger')
                ->persistent()
                ->send();

            return;
        }

        $client = app(ZnunyClient::class);
        $result = $client->testConnectionWithCredentials($apiUrl, $username, $password);

        $status = $result['status'] ?? 'failed';
        $checks = $result['checks'] ?? [];
        $counts = $result['counts'] ?? [];
        $warnings = $result['warnings'] ?? [];
        $errors = $result['errors'] ?? [];

        $color = match ($status) {
            'success' => 'success',
            'partial' => 'warning',
            default => 'danger',
        };

        $title = match ($status) {
            'success' => 'Znuny API Connection Successful',
            'partial' => 'Znuny API Connection Partial Success',
            default => 'Znuny API Connection Failed',
        };

        $body = '<strong>Checks:</strong><br>';
        foreach ($checks as $key => $passed) {
            $icon = $passed ? '✅' : '❌';
            $body .= "{$icon} ".Str::title(str_replace('_', ' ', $key)).'<br>';
        }

        if (! empty($counts)) {
            $body .= '<br><strong>Counts:</strong><br>';
            foreach ($counts as $key => $count) {
                $body .= Str::title(str_replace('_', ' ', $key)).": {$count}<br>";
            }
        }

        if (! empty($warnings)) {
            $body .= '<br><strong>Warnings:</strong><br>';
            foreach ($warnings as $warning) {
                $body .= '⚠️ '.htmlspecialchars($warning).'<br>';
            }
        }

        if (! empty($errors)) {
            $body .= '<br><strong>Errors:</strong><br>';
            foreach ($errors as $error) {
                $body .= '❌ '.htmlspecialchars($error).'<br>';
            }
        }

        AuditLogger::log(
            action: 'settings.znuny_connection_tested',
            entityType: 'settings',
            entityId: null,
            context: [
                'source' => 'form_state',
                'password_source' => $passwordSource,
                'status' => $status,
                'checks' => $checks,
                'counts' => $counts,
                'warnings' => $warnings,
                'errors' => $errors,
            ]
        );

        Notification::make()
            ->title($title)
            ->body(new HtmlString($body))
            ->color($color)
            ->persistent()
            ->send();
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

            if ($setting->type === 'json' && $setting->key === 'znuny_queue_host_mappings') {
                $initialData[$setting->key] = json_decode($setting->value, true) ?? [];

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
            'Znuny Ticket Defaults' => [],
            'Automation' => [],
            'Other' => [],
        ];

        // Ensure we pass the form's initial data state to components that need it (like Repeater keys)
        $initialData = $this->data ?? [];

        foreach ($settings as $setting) {
            if (in_array($setting->key, ['znuny_default_agent_login', 'znuny_default_agent_name'])) {
                continue;
            }

            $label = Str::title(str_replace('_', ' ', $setting->key));
            $component = null;

            if ($setting->key === 'znuny_default_agent_id') {
                $component = app(ZnunyDefaultAgentSchemaBuilder::class)->build($setting);
            } elseif ($setting->key === 'znuny_agent_exclude_logins') {
                $component = Textarea::make($setting->key)
                    ->label($label)
                    ->helperText('Znuny agent logins that must not be selectable as ticket owners in the manual ticket creation modal. Put one login per line.')
                    ->required(false)
                    ->rows(4);
            } elseif ($setting->key === 'znuny_manual_ticket_footer') {
                $component = Textarea::make($setting->key)
                    ->label($label)
                    ->helperText($setting->description)
                    ->required(false)
                    ->rows(3);
            } elseif ($setting->key === 'znuny_queue_from_host_regex') {
                $component = TextInput::make($setting->key)
                    ->label('Queue detection regex from Zabbix host')
                    ->helperText('Extracts the primary queue/customer prefix from the Zabbix host name. It must contain the named capture group (?<queue>...). Default takes the first word of the host name. Example: "ExampleCompany swiss test01" → "ExampleCompany".')
                    ->required()
                    ->rules([
                        function () {
                            return function (string $attribute, $value, \Closure $fail) {
                                if (! str_contains($value, '(?<queue>')) {
                                    $fail('The regex must contain the named capture group (?<queue>...).');
                                }

                                $pattern = '~'.str_replace('~', '\~', $value).'~u';
                                if (@preg_match($pattern, '') === false) {
                                    $fail('The regex pattern is invalid.');
                                }
                            };
                        },
                    ]);
            } elseif ($setting->key === 'znuny_customer_user_from_queue_template') {
                $component = TextInput::make($setting->key)
                    ->label('CustomerUser template from Queue')
                    ->helperText('Generates the default Znuny CustomerUser login from the primary prefix extracted from the Zabbix host name. Use <queue> as placeholder. Default: <queue>Clients. Example: primary prefix "ExampleCompany" → "ExampleCompanyClients". This does not use Queue host prefix mappings.')
                    ->required()
                    ->rules([
                        function () {
                            return function (string $attribute, $value, \Closure $fail) {
                                if (! str_contains($value, '<queue>')) {
                                    $fail('The template must contain the <queue> placeholder.');
                                }
                            };
                        },
                    ]);
            } elseif ($setting->type === 'boolean') {
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
            } elseif ($setting->key === 'znuny_queue_host_mappings') {
                $component = app(ZnunyQueueHostMappingSchemaBuilder::class)->buildRepeater($setting, $initialData);
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
            } elseif (in_array($setting->key, ['znuny_queue_from_host_regex', 'znuny_customer_user_from_queue_template', 'znuny_queue_host_mappings', 'znuny_manual_ticket_footer'])) {
                $groups['Znuny Ticket Defaults'][$setting->key] = $component;
            } elseif (str_starts_with($setting->key, 'znuny_')) {
                $groups['Znuny'][$setting->key] = $component;
            } elseif (in_array($setting->key, ['default_close_delay_hours', 'default_reopen_window_hours'])) {
                $groups['Automation'][] = $component;
            } else {
                $groups['Other'][] = $component;
            }
        }

        if (! empty($groups['Znuny'])) {
            $groups['Znuny'] = $this->buildZnunyTabGroups($groups['Znuny']);
        }

        if (! empty($groups['Znuny Ticket Defaults'])) {
            $groups['Znuny Ticket Defaults'] = $this->buildZnunyTicketDefaultsTabGroups($groups['Znuny Ticket Defaults']);
        }

        if (! empty($groups['Retention'])) {
            $retentionSection = Section::make('Retention Settings')
                ->description('Controls how long this Laravel integration app keeps local logs, statistics, cached/resolved history, closed ticket links, and failed job records. These settings do not delete data from Zabbix or Znuny.')
                ->schema($groups['Retention'])
                ->columns(1);
            $groups['Retention'] = [$retentionSection];
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

                if ($setting->type === 'json' && $setting->key === 'znuny_queue_host_mappings') {
                    $mappingService = app(ZnunyQueueHostMappingService::class);
                    $newValue = json_encode($mappingService->normalizeMappings($newValue));
                } elseif ($setting->type === 'boolean') {
                    $newValue = $newValue ? 'true' : 'false';
                } else {
                    $newValue = (string) $newValue;
                }

                $isSecretKey = SettingsService::isSecretKey($setting->key);

                // Skip updating if a password field is submitted as empty
                if ($newValue === '' && $isSecretKey) {
                    continue;
                }

                $currentPlaintext = SettingsService::string($setting->key);

                if ($currentPlaintext !== $newValue) {
                    if ($setting->key === 'znuny_default_agent_id') {
                        app(ZnunyDefaultAgentSettingsService::class)->saveDefaultAgent($setting, $newValue, $currentPlaintext, $changedSettings, $settings);

                        continue; // Skip the default save logic for this key
                    }

                    $oldValueToLog = $setting->value;
                    $newValueToLog = $newValue;
                    $valueToStore = $newValue;

                    if ($isSecretKey) {
                        $oldValueToLog = '[redacted]';
                        $newValueToLog = '[redacted]';
                        $valueToStore = SettingsService::encryptForStorage($setting->key, $newValue);
                    }

                    $changedSettings[] = [
                        'key' => $setting->key,
                        'old_value' => $oldValueToLog,
                        'new_value' => $newValueToLog,
                    ];
                    $setting->update(['value' => $valueToStore]);
                }
            }
        }

        $clearedZnunyCaches = false;
        foreach ($changedSettings as $change) {
            if (str_starts_with($change['key'], 'znuny_')) {
                Cache::forget('znuny_active_agents');
                Cache::forget('znuny.queues');
                $clearedZnunyCaches = true;
                break;
            }
        }

        app(SettingsAuditLogService::class)->logChanges($changedSettings);

        Notification::make()
            ->title('Settings saved successfully.')
            ->success()
            ->send();
    }

    private function buildZnunyTabGroups(array $z): array
    {
        $znunyGroups = [
            Section::make('Credentials')
                ->schema(array_filter([
                    $z['znuny_username'] ?? null,
                    $z['znuny_password'] ?? null,
                    Actions::make([
                        Action::make('testZnunyConnection')
                            ->label('Test Znuny API connection')
                            ->icon('heroicon-o-signal')
                            ->color('info')
                            ->action('testZnunyConnectionAction'),
                    ]),
                    Placeholder::make('tester_help')
                        ->hiddenLabel()
                        ->content('Tests current Znuny form values without saving settings.'),
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

        if (isset($z['znuny_default_agent_id'])) {
            $znunyGroups[] = $z['znuny_default_agent_id'];
        }
        if (isset($z['znuny_agent_exclude_logins'])) {
            $znunyGroups[] = $z['znuny_agent_exclude_logins'];
        }

        $knownKeys = [
            'znuny_username', 'znuny_password', 'znuny_api_url', 'znuny_web_url', 'znuny_ticket_url_template', 'znuny_api_verify_ssl', 'znuny_api_timeout',
            'znuny_default_agent_id', 'znuny_agent_exclude_logins',
        ];
        $unknownComponents = array_diff_key($z, array_flip($knownKeys));

        if (! empty($unknownComponents)) {
            $znunyGroups[] = Section::make('Other')
                ->schema(array_values($unknownComponents))->columns(1);
        }

        return $znunyGroups;
    }

    private function buildZnunyTicketDefaultsTabGroups(array $zd): array
    {
        $zdGroups = [];

        if (isset($zd['znuny_queue_from_host_regex']) || isset($zd['znuny_customer_user_from_queue_template']) || isset($zd['znuny_manual_ticket_footer'])) {
            $zdGroups[] = Section::make('Ticket default rules')
                ->description('These rules only generate default suggestions for manual ticket creation. The operator will still be able to override Queue and CustomerUser before creating a ticket.')
                ->schema(array_filter([
                    $zd['znuny_queue_from_host_regex'] ?? null,
                    $zd['znuny_customer_user_from_queue_template'] ?? null,
                    $zd['znuny_manual_ticket_footer'] ?? null,
                ]))->columns(1);
        }

        if (isset($zd['znuny_queue_host_mappings'])) {
            $zdGroups[] = Section::make('Queue host prefix mappings')
                ->description('Fallback Queue mapping for standardized Zabbix host prefixes. CustomerUser is still generated from the original host prefix.')
                ->schema([
                    $zd['znuny_queue_host_mappings'],
                ])
                ->columns(1)
                ->headerActions([
                    app(ZnunyQueueHostMappingSchemaBuilder::class)->getSaveAction(),
                    app(ZnunyQueueHostMappingSchemaBuilder::class)->getScanMissingAction(),
                ]);
        }

        return $zdGroups;
    }
}
