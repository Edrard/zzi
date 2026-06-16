<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyAgentService;
use App\Services\Znuny\ZnunyQueueHostMappingService;
use App\Services\Znuny\ZnunyQueueService;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
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
use Illuminate\Database\Eloquent\Collection;
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
                $component = $this->getZnunyDefaultAgentIdComponent($setting);
            } elseif ($setting->key === 'znuny_agent_exclude_logins') {
                $component = Textarea::make($setting->key)
                    ->label($label)
                    ->helperText('Znuny agent logins that must not be selectable as ticket owners in the manual ticket creation modal. Put one login per line.')
                    ->required(false)
                    ->rows(4);
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
                $component = $this->getZnunyQueueHostMappingsComponent($setting, $initialData);
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
            } elseif (in_array($setting->key, ['znuny_queue_from_host_regex', 'znuny_customer_user_from_queue_template', 'znuny_queue_host_mappings'])) {
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
            $z = $groups['Znuny'];

            $znunyGroups = [
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

            $groups['Znuny'] = $znunyGroups;
        }

        if (! empty($groups['Znuny Ticket Defaults'])) {
            $zd = $groups['Znuny Ticket Defaults'];
            $zdGroups = [];

            if (isset($zd['znuny_queue_from_host_regex']) || isset($zd['znuny_customer_user_from_queue_template'])) {
                $zdGroups[] = Section::make('Ticket default rules')
                    ->description('These rules only generate default suggestions for manual ticket creation. The operator will still be able to override Queue and CustomerUser before creating a ticket.')
                    ->schema(array_filter([
                        $zd['znuny_queue_from_host_regex'] ?? null,
                        $zd['znuny_customer_user_from_queue_template'] ?? null,
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
                        $this->getSaveMappingsAction(),
                        $this->getScanMissingAction(),
                    ]);
            }

            $groups['Znuny Ticket Defaults'] = $zdGroups;
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
                        $this->saveZnunyDefaultAgent($setting, $newValue, $currentPlaintext, $changedSettings, $settings);

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

        $this->logChanges($changedSettings);

        Notification::make()
            ->title('Settings saved successfully.')
            ->success()
            ->send();
    }

    private function getZnunyDefaultAgentIdComponent(Setting $setting): Select
    {
        $options = [];
        $warning = null;

        try {
            $agentService = app(ZnunyAgentService::class);
            $selectableAgents = $agentService->getSelectableAgents(failSilently: true);
            foreach ($selectableAgents as $agent) {
                $options[$agent['id']] = $agent['label'];
            }

            if ($agentService->lastError()) {
                $warning = 'Could not load active agents from Znuny API.';
            }
        } catch (\Throwable $e) {
            $warning = 'Could not load active agents from Znuny API.';
        }

        $currentId = SettingsService::string('znuny_default_agent_id');
        if ($currentId !== '' && ! isset($options[$currentId]) && empty($warning)) {
            // Check if it's excluded or completely inactive
            $allAgents = $agentService->getAgents(failSilently: true);
            $isActive = collect($allAgents)->contains('id', (int) $currentId);

            if ($isActive) {
                $warning = 'The currently selected default agent is excluded from selectable agents. Please choose another agent.';
            } else {
                $warning = "The currently selected agent (ID: {$currentId}) is no longer returned by the active agents list. Please select a valid agent.";
            }
        }

        $helpText = 'Used only by future automatic ticket creation. Manual ticket creation requires the operator to choose an owner.';
        if ($warning) {
            $helpText = "<span style=\"color: #e11d48; font-weight: bold;\">Warning: {$warning}</span><br>".$helpText;
        }

        return Select::make($setting->key)
            ->label('Default agent for automatic ticket creation')
            ->helperText(new HtmlString($helpText))
            ->options($options)
            ->searchable()
            ->required(false);
    }

    private function getZnunyQueueHostMappingsComponent(Setting $setting, array $initialData): Repeater
    {
        $queueService = app(ZnunyQueueService::class);
        $qResult = $queueService->getSelectableQueuesResult();
        $queueOptions = $qResult['options'] ?? [];
        $queueError = $qResult['error'] ?? null;

        $savedMappings = $initialData['znuny_queue_host_mappings'] ?? [];
        if (is_string($savedMappings)) {
            $savedMappings = json_decode($savedMappings, true) ?? [];
        }

        if (is_array($savedMappings)) {
            foreach ($savedMappings as $m) {
                $qName = $m['queue_name'] ?? null;
                if ($qName && ! isset($queueOptions[$qName])) {
                    $queueOptions[$qName] = $qName.($queueError ? ' (Saved)' : '');
                }
            }
        }

        return Repeater::make($setting->key)
            ->label('Queue host prefix mappings')
            ->helperText(new HtmlString('Maps primary Zabbix host prefixes to existing Znuny queues. Used only when the primary queue candidate is not found in Znuny.'.($queueError ? '<br><span style="color: #e11d48; font-weight: bold;">'.$queueError.'</span>' : '')))
            ->schema([
                TextInput::make('host_prefix')
                    ->label('Host prefix')
                    ->helperText('Example: TestCompany')
                    ->dehydrateStateUsing(fn ($state) => trim($state))
                    ->distinct()
                    ->required(false),
                Select::make('queue_name')
                    ->label('Queue name')
                    ->options($queueOptions)
                    ->searchable()
                    ->required(false),
                TextInput::make('note')
                    ->label('Note')
                    ->required(false),
            ])
            ->columns(3)
            ->defaultItems(0)
            ->reorderable(false);
    }

    private function getSaveMappingsAction(): Action
    {
        return Action::make('saveMappings')
            ->label('Save queue mappings')
            ->icon('heroicon-o-check')
            ->color('success')
            ->action(function (Settings $livewire) {
                if (auth()->user()->role !== 'admin') {
                    abort(403, 'Only admins can modify settings.');
                }

                $mappingService = app(ZnunyQueueHostMappingService::class);
                $state = $livewire->data['znuny_queue_host_mappings'] ?? [];
                $mappingService->saveMappings($state);

                Notification::make()
                    ->title('Queue mappings saved successfully.')
                    ->success()
                    ->send();
            });
    }

    private function getScanMissingAction(): Action
    {
        return Action::make('scanMissing')
            ->label('Scan current problems for missing queue mappings')
            ->button()
            ->action(function (Settings $livewire) {
                $mappingService = app(ZnunyQueueHostMappingService::class);

                $fullState = $livewire->form->getRawState();
                $currentState = $fullState['znuny_queue_host_mappings'] ?? [];
                $result = $mappingService->scanMissingMappings($currentState);

                $drafts = $result['drafts'];
                $stats = $result['stats'];

                if (! empty($drafts)) {
                    $newState = $currentState;
                    foreach ($drafts as $draft) {
                        $newState[(string) Str::uuid()] = $draft;
                    }
                    $fullState['znuny_queue_host_mappings'] = $newState;
                    $livewire->form->fill($fullState);
                }

                $message = "Scanned {$stats['scanned']} problems ({$stats['unique_prefixes']} unique prefixes).\n"
                    ."Added {$stats['added']} draft mappings.\n"
                    ."Skipped {$stats['skipped_existing_queue']} existing queues.\n"
                    ."Skipped {$stats['skipped_existing_mapping']} existing mappings.\n"
                    ."Failed API checks: {$stats['failed_api']}.";

                Notification::make()
                    ->title('Scan Complete')
                    ->body($message)
                    ->success()
                    ->send();
            });
    }

    private function saveZnunyDefaultAgent(Setting $setting, $newValue, string $currentPlaintext, array &$changedSettings, Collection $settings): void
    {
        $agentService = app(ZnunyAgentService::class);
        $selectableAgents = $agentService->getSelectableAgents(failSilently: true);
        $selectedAgent = null;

        if ($newValue !== '') {
            if ($agentService->lastError()) {
                // Agent loading failed, do not destroy the existing stored value/snapshot
                return;
            }

            $selectedAgent = collect($selectableAgents)->firstWhere('id', (int) $newValue);
            if (! $selectedAgent) {
                // Invalid selection, do not silently save it
                return;
            }
        }

        $newLogin = $selectedAgent ? $selectedAgent['login'] : '';
        $newName = $selectedAgent ? (string) $selectedAgent['name'] : '';

        // Track changes for ID
        $changedSettings[] = [
            'key' => 'znuny_default_agent_id',
            'old_value' => $currentPlaintext,
            'new_value' => $newValue,
        ];
        $setting->update(['value' => $newValue]);

        // Update login and name
        foreach (['znuny_default_agent_login' => $newLogin, 'znuny_default_agent_name' => $newName] as $k => $v) {
            $subSetting = $settings->firstWhere('key', $k);
            if ($subSetting && $subSetting->value !== $v) {
                $changedSettings[] = [
                    'key' => $k,
                    'old_value' => $subSetting->value,
                    'new_value' => $v,
                ];
                $subSetting->update(['value' => $v]);
            }
        }
    }

    private function logChanges(array $changedSettings): void
    {
        if (empty($changedSettings)) {
            return;
        }

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
}
