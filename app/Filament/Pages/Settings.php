<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\SettingsAuditLogService;
use App\Services\SettingsService;
use App\Services\Zabbix\ZabbixClient;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyDefaultAgentSchemaBuilder;
use App\Services\Znuny\ZnunyDefaultAgentSettingsService;
use App\Services\Znuny\ZnunyQueueHostMappingSchemaBuilder;
use App\Services\Znuny\ZnunyQueueHostMappingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
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

    protected static string|\UnitEnum|null $navigationGroup = 'Administration / Settings';

    protected static ?int $navigationSort = 1;

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

    public function testZabbixConnectionAction(): void
    {
        $data = $this->form->getRawState();
        $apiUrl = $data['zabbix_api_url'] ?? '';
        $token = $data['zabbix_api_token'] ?? '';
        $timeout = $data['zabbix_api_timeout'] ?? 15;
        $verifySsl = $data['zabbix_api_verify_ssl'] ?? true;

        $tokenSource = 'form';
        if (empty($token)) {
            $token = SettingsService::string('zabbix_api_token', '');
            $tokenSource = empty($token) ? 'missing' : 'saved';
        }

        $errorResult = null;
        if (empty($apiUrl)) {
            $errorResult = 'Zabbix API URL is required.';
        } elseif (empty($token)) {
            $errorResult = 'Zabbix API Token is required.';
        }

        if ($errorResult !== null) {
            AuditLogger::log(
                action: 'settings.zabbix_connection_tested',
                entityType: 'settings',
                entityId: null,
                context: [
                    'source' => 'form_state',
                    'token_source' => $tokenSource,
                    'status' => 'failed',
                    'errors' => [$errorResult],
                ]
            );

            Notification::make()
                ->title('Zabbix API Connection Failed')
                ->body(new HtmlString('<strong>Errors:</strong><br>❌ '.htmlspecialchars($errorResult).'<br>'))
                ->color('danger')
                ->persistent()
                ->send();

            return;
        }

        $client = app(ZabbixClient::class);

        try {
            $result = $client->testConnectionWithCredentials($apiUrl, $token, (int) $timeout, (bool) $verifySsl);

            $version = $result['version'] ?? 'Unknown';

            AuditLogger::log(
                action: 'settings.zabbix_connection_tested',
                entityType: 'settings',
                entityId: null,
                context: [
                    'source' => 'form_state',
                    'token_source' => $tokenSource,
                    'status' => 'success',
                    'version' => $version,
                ]
            );

            Notification::make()
                ->title('Zabbix API Connection Successful')
                ->body("Connected successfully. API Version: {$version}")
                ->color('success')
                ->persistent()
                ->send();

        } catch (\Exception $e) {
            AuditLogger::log(
                action: 'settings.zabbix_connection_tested',
                entityType: 'settings',
                entityId: null,
                context: [
                    'source' => 'form_state',
                    'token_source' => $tokenSource,
                    'status' => 'failed',
                    'errors' => [$e->getMessage()],
                ]
            );

            Notification::make()
                ->title('Zabbix API Connection Failed')
                ->body(new HtmlString('<strong>Errors:</strong><br>❌ '.htmlspecialchars($e->getMessage()).'<br>'))
                ->color('danger')
                ->persistent()
                ->send();
        }
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
            'Cache' => [],
            'Zabbix' => [],
            'Znuny' => [],
            'Znuny Ticket Defaults' => [],
            'Znuny Sync' => [],
            'Automation' => [],
            'Other' => [],
        ];

        // Ensure we pass the form's initial data state to components that need it (like Repeater keys)
        $initialData = $this->data ?? [];

        foreach ($settings as $setting) {
            if (in_array($setting->key, ['znuny_default_agent_login', 'znuny_default_agent_name', 'manual_ticket_auto_close_enabled'])) {
                continue;
            }

            $label = Str::title(str_replace('_', ' ', $setting->key));
            $description = $setting->description;

            $overrides = [
                'zabbix_problem_cache_ttl_minutes' => [
                    'label' => 'Problem Presence Window (minutes)',
                    'description' => 'How long a last-seen Zabbix problem is still considered present after it stops appearing in Zabbix poll results. This helps avoid premature resolved-state transitions between poll cycles.',
                ],
                'znuny_agent_cache_ttl_minutes' => [
                    'label' => 'Znuny Agent Cache TTL Minutes',
                    'description' => 'How long Znuny agent list data is cached. 0 disables this cache.',
                ],
                'znuny_queue_cache_ttl_minutes' => [
                    'label' => 'Znuny Queue Cache TTL Minutes',
                    'description' => 'How long Znuny queue list data is cached. 0 disables this cache.',
                ],
                'znuny_ticket_snapshot_cache_ttl_minutes' => [
                    'label' => 'Znuny Ticket Snapshot Cache TTL Minutes',
                    'description' => 'How long linked ticket snapshot data may be cached before refresh. 0 disables this cache.',
                ],
                'znuny_detailed_sync_audit_enabled' => [
                    'label' => 'Enable Detailed Sync Audit Log',
                    'description' => 'Write detailed linked-ticket sync events to the audit log. Keep disabled unless troubleshooting.',
                ],
                'znuny_ticket_url_template' => [
                    'label' => 'Znuny Ticket URL Template',
                    'description' => 'Template used to open a Znuny ticket in the Znuny web UI. Supported placeholders: {ticket_id} for the internal Znuny TicketID. Example: https://znuny.example.com/otrs/index.pl?Action=AgentTicketZoom;TicketID={ticket_id}',
                ],
                'zabbix_problem_url_template' => [
                    'label' => 'Zabbix Problem URL Template',
                    'description' => 'Template used to open a Zabbix problem in the Zabbix web UI. Use {trigger_id} as the trigger ID placeholder. Example for Zabbix 7.0: https://zabbix.example.com/zabbix.php?show=1&action=problem.view&triggerids%5B%5D={trigger_id}',
                ],
                'znuny_linked_ticket_sync_batch_size' => [
                    'label' => 'Linked Ticket Sync Batch Size',
                    'description' => 'Maximum number of eligible linked tickets processed per sync run. 0 means all eligible tickets.',
                ],
                'znuny_linked_ticket_sync_interval_minutes' => [
                    'label' => 'Linked Ticket Sync Interval Minutes',
                    'description' => 'How often scheduled linked-ticket synchronization should run.',
                ],
                'manual_ticket_auto_close_schedule_mode' => [
                    'label' => 'Manual Ticket Auto-close Scheduler Mode',
                    'description' => 'Controls how the scheduler handles auto-closing of manual tickets.',
                ],
                'default_close_delay_hours' => [
                    'label' => 'Default Close Delay Hours',
                    'description' => 'Hours a linked Zabbix problem must remain resolved before automatic ticket close can run.',
                ],
                'default_reopen_window_hours' => [
                    'label' => 'Default Reopen Window Hours',
                    'description' => 'Hours after ticket close during which the same logical Zabbix problem is shown as a recent candidate for reopening. Reopen is still a manual operator action.',
                ],
                'manual_ticket_flap_threshold' => [
                    'label' => 'Flap Threshold',
                    'description' => 'Number of times the same linked problem may return after resolving before it is marked as flapping. 0 disables flapping detection.',
                ],
                'manual_ticket_extra_flapping_delay_hours' => [
                    'label' => 'Extra Flapping Delay Hours',
                    'description' => 'Additional close delay added after flapping is detected for a linked manual ticket.',
                ],
            ];

            if (isset($overrides[$setting->key])) {
                $label = $overrides[$setting->key]['label'];
                $description = $overrides[$setting->key]['description'];
            }

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
            } elseif ($setting->key === 'linked_ticket_manual_close_default_reason') {
                $component = Textarea::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->required(true)
                    ->rows(2);
            } elseif ($setting->key === 'manual_ticket_reopen_note_template') {
                $component = Textarea::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->required(true)
                    ->rows(2);
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
                    ->helperText($description)
                    ->required();
            } elseif ($setting->type === 'integer') {
                $min = 0;
                if ($setting->key === 'cleanup_batch_size' || $setting->key === 'znuny_linked_ticket_sync_interval_minutes') {
                    $min = 1;
                }

                $component = TextInput::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->numeric()
                    ->integer()
                    ->minValue($min)
                    ->required();
            } elseif ($setting->key === 'znuny_queue_host_mappings') {
                $component = app(ZnunyQueueHostMappingSchemaBuilder::class)->buildRepeater($setting, $initialData);
            } elseif ($setting->type === 'json') {
                $component = Textarea::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->rule('json')
                    ->required();
            } elseif ($setting->key === 'manual_ticket_auto_close_schedule_mode') {
                $component = Select::make($setting->key)
                    ->label($label)
                    ->helperText('disabled: scheduler will not auto-close manual tickets; dry_run: scheduler logs what would be closed without changing Znuny; execute: scheduler closes eligible manual tickets using the verified /TicketClose workflow.')
                    ->options([
                        'disabled' => 'Disabled',
                        'dry_run' => 'Dry Run',
                        'execute' => 'Execute',
                    ])
                    ->required();
            } elseif ($setting->key === 'app_display_timezone') {
                $component = Select::make($setting->key)
                    ->label($label)
                    ->helperText('Timezone used to display dates and times in the admin interface. Backend timestamps and scheduler logic remain unchanged.')
                    ->options(array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()))
                    ->searchable()
                    ->required();
            } else {
                $input = TextInput::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->required(! in_array($setting->key, ['zabbix_problem_url_template', 'znuny_ticket_url_template']));

                if (in_array($setting->key, ['zabbix_api_token', 'znuny_password'])) {
                    $input->password()
                        ->revealable()
                        ->placeholder('Leave empty to keep current password')
                        ->required(false);
                }

                $component = $input;
            }

            if (in_array($setting->key, ['cleanup_enabled', 'cleanup_batch_size', 'app_display_timezone'])) {
                $groups['General'][] = $component;
            } elseif (in_array($setting->key, ['retention_action_logs_days', 'retention_closed_tickets_days', 'retention_failed_jobs_days', 'retention_resolved_days', 'retention_statistics_days'])) {
                $groups['Retention'][] = $component;
            } elseif (in_array($setting->key, ['zabbix_api_url', 'zabbix_api_token', 'zabbix_api_timeout', 'zabbix_api_verify_ssl', 'zabbix_poll_interval_minutes', 'zabbix_problem_cache_ttl_minutes', 'zabbix_problem_limit', 'zabbix_exclude_suppressed_problems', 'zabbix_problem_url_template'])) {
                $groups['Zabbix'][$setting->key] = $component;
            } elseif (in_array($setting->key, ['znuny_queue_from_host_regex', 'znuny_customer_user_from_queue_template', 'znuny_queue_host_mappings', 'znuny_manual_ticket_footer', 'znuny_default_agent_id', 'linked_ticket_manual_close_default_reason', 'manual_ticket_reopen_note_template'])) {
                $groups['Znuny Ticket Defaults'][$setting->key] = $component;
            } elseif (in_array($setting->key, ['znuny_queue_cache_ttl_minutes', 'znuny_agent_cache_ttl_minutes', 'znuny_ticket_snapshot_cache_ttl_minutes'])) {
                $groups['Cache'][] = $component;
            } elseif (in_array($setting->key, ['znuny_linked_ticket_sync_interval_minutes', 'znuny_linked_ticket_sync_batch_size', 'znuny_detailed_sync_audit_enabled'])) {
                $groups['Znuny Sync'][] = $component;
            } elseif (str_starts_with($setting->key, 'znuny_')) {
                $groups['Znuny'][$setting->key] = $component;
            } elseif (in_array($setting->key, ['default_close_delay_hours', 'default_reopen_window_hours', 'manual_ticket_auto_close_schedule_mode', 'manual_ticket_flap_threshold', 'manual_ticket_extra_flapping_delay_hours'])) {
                $groups['Automation'][$setting->key] = $component;
            } else {
                $groups['Other'][] = $component;
            }
        }

        if (! empty($groups['Zabbix'])) {
            $groups['Zabbix'] = $this->buildZabbixTabGroups($groups['Zabbix']);
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

        if (! empty($groups['Automation'])) {
            $groups['Automation'] = $this->buildAutomationTabGroups($groups['Automation']);
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

    private function buildZabbixTabGroups(array $z): array
    {
        return [
            Tabs::make('ZabbixTabs')
                ->tabs([
                    Tab::make('Connection')
                        ->schema(array_filter([
                            $z['zabbix_api_url'] ?? null,
                            $z['zabbix_api_token'] ?? null,
                            $z['zabbix_api_timeout'] ?? null,
                            $z['zabbix_api_verify_ssl'] ?? null,
                            Actions::make([
                                Action::make('testZabbixConnection')
                                    ->label('Test Zabbix API connection')
                                    ->icon('heroicon-o-signal')
                                    ->color('info')
                                    ->action('testZabbixConnectionAction'),
                            ]),
                            Placeholder::make('zabbix_tester_help')
                                ->hiddenLabel()
                                ->content('Tests current Zabbix form values without saving settings.'),
                        ]))
                        ->columns(1),
                    Tab::make('Problem Handling & UI')
                        ->schema(array_filter([
                            $z['zabbix_poll_interval_minutes'] ?? null,
                            $z['zabbix_problem_cache_ttl_minutes'] ?? null,
                            $z['zabbix_problem_limit'] ?? null,
                            $z['zabbix_exclude_suppressed_problems'] ?? null,
                            $z['zabbix_problem_url_template'] ?? null,
                        ]))
                        ->columns(1),
                ]),
        ];
    }

    private function getZnunyConnectionTestAction(string $name): Actions
    {
        return Actions::make([
            Action::make($name)
                ->label('Test Znuny API Connection')
                ->icon('heroicon-o-signal')
                ->color('info')
                ->action('testZnunyConnectionAction'),
        ]);
    }

    private function getZnunyConnectionTestHelperPlaceholder(string $name): Placeholder
    {
        return Placeholder::make($name)
            ->hiddenLabel()
            ->content('Tests current Znuny form values without saving settings.');
    }

    private function buildZnunyTabGroups(array $z): array
    {
        $tabs = [
            Tab::make('Credentials')
                ->schema(array_filter([
                    $z['znuny_username'] ?? null,
                    $z['znuny_password'] ?? null,
                    $this->getZnunyConnectionTestAction('testZnunyConnection_Credentials'),
                    $this->getZnunyConnectionTestHelperPlaceholder('tester_help_Credentials'),
                ]))->columns(1),

            Tab::make('Endpoints & Connection')
                ->schema(array_filter([
                    $z['znuny_api_url'] ?? null,
                    $z['znuny_web_url'] ?? null,
                    $z['znuny_ticket_url_template'] ?? null,
                    $z['znuny_api_verify_ssl'] ?? null,
                    $z['znuny_api_timeout'] ?? null,
                    $this->getZnunyConnectionTestAction('testZnunyConnection_Endpoints'),
                    $this->getZnunyConnectionTestHelperPlaceholder('tester_help_Endpoints'),
                ]))->columns(1),

            Tab::make('Agents')
                ->schema(array_filter([
                    $z['znuny_agent_exclude_logins'] ?? null,
                ]))->columns(1),
        ];

        return [
            Tabs::make('ZnunyTabs')->tabs($tabs),
        ];
    }

    private function buildZnunyTicketDefaultsTabGroups(array $zd): array
    {
        $tabs = [];

        if (isset($zd['znuny_queue_host_mappings'])) {
            $tabs[] = Tab::make('Queue Host Prefix Mappings')
                ->schema([
                    Section::make('Queue host prefix mappings')
                        ->description('Fallback Queue mapping for standardized Zabbix host prefixes. CustomerUser is still generated from the original host prefix.')
                        ->schema([
                            $zd['znuny_queue_host_mappings'],
                        ])
                        ->columns(1)
                        ->headerActions([
                            app(ZnunyQueueHostMappingSchemaBuilder::class)->getSaveAction(),
                            app(ZnunyQueueHostMappingSchemaBuilder::class)->getScanMissingAction(),
                        ]),
                ]);
        }

        $tabs[] = Tab::make('Ticket Default Rules')
            ->schema(array_filter([
                $zd['znuny_queue_from_host_regex'] ?? null,
                $zd['znuny_customer_user_from_queue_template'] ?? null,
                $zd['znuny_default_agent_id'] ?? null,
                $zd['znuny_manual_ticket_footer'] ?? null,
                $zd['linked_ticket_manual_close_default_reason'] ?? null,
                $zd['manual_ticket_reopen_note_template'] ?? null,
            ]))->columns(1);

        return [
            Tabs::make('ZnunyTicketDefaultsTabs')->tabs($tabs),
        ];
    }

    private function buildAutomationTabGroups(array $a): array
    {
        return [
            Tabs::make('AutomationTabs')
                ->tabs([
                    Tab::make('Manual')
                        ->schema(array_filter([
                            $a['manual_ticket_auto_close_schedule_mode'] ?? null,
                            $a['default_close_delay_hours'] ?? null,
                            $a['default_reopen_window_hours'] ?? null,
                            $a['manual_ticket_flap_threshold'] ?? null,
                            $a['manual_ticket_extra_flapping_delay_hours'] ?? null,
                        ]))
                        ->columns(1),
                    Tab::make('Automatic')
                        ->schema([
                            Placeholder::make('auto_tickets_placeholder')
                                ->label('Auto Ticket Automation')
                                ->content('Automatic ticket creation and auto-ticket lifecycle rules will be configured here later. No auto-ticket automation is active in this stage.'),
                        ])
                        ->columns(1),
                ]),
        ];
    }
}
