<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\MailNotificationService;
use App\Services\RuntimeCacheMaintenanceService;
use App\Services\SettingsAuditLogService;
use App\Services\SettingsService;
use App\Services\Zabbix\ZabbixAttentionHighlightStyleService;
use App\Services\Zabbix\ZabbixClient;
use App\Services\Znuny\ZnunyCachedLookupService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyQueueHostMappingSchemaBuilder;
use App\Services\Znuny\ZnunyQueueHostMappingService;
use App\Services\Znuny\ZnunyTicketArticleCacheService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Repeater;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    private const EXPLICIT_MAIL_KEYS = [
        'mail_notifications_enabled',
        'mail_transport',
        'mail_admin_recipients',
        'mail_from_address',
        'mail_from_name',
        'mail_sendmail_path',
        'mail_smtp_host',
        'mail_smtp_port',
        'mail_smtp_encryption',
        'mail_smtp_username',
        'mail_smtp_password',
        'mail_smtp_timeout_seconds',
    ];

    private const EXPLICIT_SCHEDULER_KEYS = [
        'scheduled_tasks_enabled',
        'scheduled_tasks_max_processed_per_run',
        'scheduled_tasks_command_runtime_seconds',
        'scheduled_tasks_pause_minutes',
        'scheduled_tasks_missed_run_max_age_days',
        'scheduled_tasks_auto_disable_on_failures',
        'scheduled_tasks_failure_threshold',
    ];

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected string $view = 'filament.pages.settings';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 10;

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

    public function testMailConnectionAction(): void
    {
        $data = $this->form->getRawState();
        $transport = $data['mail_transport'] ?? 'smtp';

        $errorResult = null;
        if ($transport === 'smtp') {
            if (($data['mail_smtp_password'] ?? '') === '') {
                if (($data['mail_smtp_password_clear'] ?? false) === true) {
                    $data['mail_smtp_password'] = '';
                } else {
                    $data['mail_smtp_password'] = SettingsService::string('mail_smtp_password', '');
                }
            }
            if (($data['mail_smtp_host'] ?? '') === '') {
                $errorResult = 'SMTP Host is required.';
            }
        } elseif ($transport === 'sendmail') {
            if (($data['mail_sendmail_path'] ?? '') === '') {
                $errorResult = 'Sendmail Path is required.';
            }
        } else {
            $errorResult = 'Unsupported mail transport.';
        }

        if ($errorResult === null) {
            if (($data['mail_from_address'] ?? '') === '') {
                $errorResult = 'From Address is required.';
            } elseif (($data['mail_admin_recipients'] ?? '') === '') {
                $errorResult = 'Admin Recipients are required to send a test email.';
            }
        }

        if ($errorResult !== null) {
            Notification::make()
                ->title('Test Email Failed')
                ->body(new HtmlString('<strong>Errors:</strong><br>❌ '.htmlspecialchars($errorResult).'<br>'))
                ->color('danger')
                ->persistent()
                ->send();

            return;
        }

        try {
            app(MailNotificationService::class)->sendTestEmail($data);

            Notification::make()
                ->title('Test Email Sent')
                ->body('Check the configured admin recipients for the test email.')
                ->color('success')
                ->persistent()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Test Email Failed')
                ->body(new HtmlString('<strong>Errors:</strong><br>❌ '.htmlspecialchars($e->getMessage()).'<br>'))
                ->color('danger')
                ->persistent()
                ->send();
        }
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

    private function authorizeRuntimeCacheMaintenance(): void
    {
        abort_unless(
            auth()->user()?->role === 'admin',
            403,
            'Only admins can clear runtime caches.'
        );
    }

    private function executeCacheMaintenance(callable $serviceCall, string $successTitle, string $successBody, string $failureBody): void
    {
        $this->authorizeRuntimeCacheMaintenance();

        try {
            $serviceCall();

            Notification::make()
                ->title($successTitle)
                ->body($successBody)
                ->success()
                ->send();
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title('Cache clearing failed')
                ->body($failureBody)
                ->danger()
                ->send();
        }
    }

    public function clearSettingsCacheAction(): void
    {
        $this->executeCacheMaintenance(
            fn () => app(RuntimeCacheMaintenanceService::class)->clearSettingsCache(),
            'Settings cache cleared',
            'Cached application settings were cleared successfully.',
            'The Settings cache could not be cleared. Review the application logs for details.'
        );
    }

    public function clearZnunyAgentCacheAction(): void
    {
        $this->executeCacheMaintenance(
            fn () => app(RuntimeCacheMaintenanceService::class)->clearZnunyAgentCache(),
            'Znuny agent cache cleared',
            'Cached Znuny agent data was cleared successfully.',
            'The Znuny Agent cache could not be cleared. Review the application logs for details.'
        );
    }

    public function clearZnunyQueueCacheAction(): void
    {
        $this->executeCacheMaintenance(
            fn () => app(RuntimeCacheMaintenanceService::class)->clearZnunyQueueCache(),
            'Znuny queue cache cleared',
            'Cached Znuny queue data was cleared successfully.',
            'The Znuny Queue cache could not be cleared. Review the application logs for details.'
        );
    }

    public function clearZnunyLookupCacheAction(): void
    {
        $this->executeCacheMaintenance(
            fn () => app(RuntimeCacheMaintenanceService::class)->clearZnunyLookupCache(),
            'Znuny lookup cache cleared',
            'Cached Znuny lookup data was invalidated successfully.',
            'The Znuny Lookup cache could not be cleared. Review the application logs for details.'
        );
    }

    public function clearTicketArticleCacheAction(): void
    {
        $this->executeCacheMaintenance(
            fn () => app(RuntimeCacheMaintenanceService::class)->clearTicketArticleCache(),
            'Ticket article cache cleared',
            'Cached Znuny ticket article data was invalidated successfully.',
            'The Ticket Article cache could not be cleared. Review the application logs for details.'
        );
    }

    public function mount(): void
    {
        Artisan::call('app:ensure-settings-defaults');

        $settings = Setting::query()->orderBy('key')->get();
        $initialData = [];

        foreach ($settings as $setting) {
            if (in_array($setting->key, ['zabbix_api_token', 'znuny_password', 'mail_smtp_password'])) {
                $initialData[$setting->key] = '';

                continue;
            }

            if ($setting->type === 'json') {
                $decoded = json_decode($setting->value, true) ?? [];

                if ($setting->key === 'znuny_global_queue_exclusion_regexes') {
                    $normalized = [];
                    foreach ($decoded as $item) {
                        if (is_string($item) && trim($item) !== '') {
                            $normalized[] = ['regex' => trim($item)];
                        } elseif (is_array($item) && isset($item['regex']) && trim($item['regex']) !== '') {
                            $normalized[] = ['regex' => trim($item['regex'])];
                        }
                    }
                    $initialData[$setting->key] = $normalized;
                } else {
                    $initialData[$setting->key] = $decoded;
                }

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
            'General' => [
                'Main' => [],
                'Mail' => [],
            ],
            'Scheduler' => [],
            'Retention' => [],
            'Audit Log' => [],
            'Cache' => [],
            'Zabbix' => [],
            'Znuny' => [],
            'Znuny Ticket Defaults' => [],
            'Automation' => [],
            'Other' => [],
        ];

        // Ensure we pass the form's initial data state to components that need it (like Repeater keys)
        $initialData = $this->data ?? [];

        foreach ($settings as $setting) {
            if (in_array($setting->key, ['manual_ticket_auto_close_enabled', 'scheduled_tasks_paused_until', 'scheduled_tasks_pause_reason', 'scheduled_tasks_disabled_reason'])) {
                continue;
            }

            if (in_array($setting->key, self::EXPLICIT_MAIL_KEYS) || in_array($setting->key, self::EXPLICIT_SCHEDULER_KEYS)) {
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
                    'label' => 'Linked Tickets Detailed Sync Audit',
                    'description' => 'Write detailed per-ticket Linked Tickets sync audit events such as updated, missing, and failed. Keep disabled unless troubleshooting.',
                ],
                'zabbix_problem_sync_audit_enabled' => [
                    'label' => 'Current Problems Sync Audit',
                    'description' => 'Write summary audit records for scheduled Zabbix problem polling. Manual refreshes will be audited separately in a later stage regardless of this setting.',
                ],
                'znuny_ticket_workspace_sync_audit_enabled' => [
                    'label' => 'Ticket Workspace Sync Audit',
                    'description' => 'Write summary audit records for scheduled Ticket Workspace cache warming. Manual refreshes will be audited separately in a later stage regardless of this setting.',
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
                'znuny_ticket_workspace_enabled' => [
                    'label' => 'Enable Ticket Workspace',
                    'description' => $setting->description,
                ],
                'znuny_ticket_cache_refresh_interval_minutes' => [
                    'label' => 'Active Cache Refresh Interval (Minutes)',
                    'description' => $setting->description,
                ],
                'znuny_ticket_cache_default_limit' => [
                    'label' => 'Default Page Size',
                    'description' => $setting->description,
                ],
                'znuny_ticket_cache_max_pages_per_run' => [
                    'label' => 'Max Pages Per Run',
                    'description' => $setting->description,
                ],
                'znuny_ticket_cache_ttl_minutes' => [
                    'label' => 'Active Ticket Cache TTL (Minutes)',
                    'description' => $setting->description,
                ],

                'znuny_ticket_workspace_active_state_type_ids' => [
                    'label' => 'Active State Types',
                    'description' => 'Select which Znuny state types should be included in the Ticket Workspace active working set.',
                ],
                'znuny_closed_ticket_window_days' => [
                    'label' => 'Recent Closed Window (Days)',
                    'description' => 'How many recent days of closed tickets will be available in Ticket Workspace. Physical Redis retention is managed automatically and equals 6× this window.',
                ],
                'znuny_closed_ticket_small_sync_interval_minutes' => [
                    'label' => 'Small Sync Interval (Minutes)',
                    'description' => 'How often the small closed-ticket sync should refresh recent closed tickets.',
                ],
                'znuny_closed_ticket_sync_audit_auto_enabled' => [
                    'label' => 'Log Automatic Closed-Ticket Syncs',
                    'description' => 'Write Audit Log entries for automatic closed-ticket syncs. Manual syncs are always audited.',
                ],
            ];

            if (isset($overrides[$setting->key])) {
                $label = $overrides[$setting->key]['label'];
                $description = $overrides[$setting->key]['description'];
            }

            $component = null;

            if ($setting->key === 'znuny_agent_exclude_logins') {
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

                if ($setting->key === 'zabbix_attention_highlighting_enabled') {
                    $component->live();
                }
            } elseif ($setting->type === 'integer') {
                $min = 0;
                $max = null;

                if ($setting->key === 'cleanup_batch_size' || $setting->key === 'znuny_linked_ticket_sync_interval_minutes' || $setting->key === 'znuny_closed_ticket_window_days' || $setting->key === 'znuny_closed_ticket_small_sync_interval_minutes') {
                    $min = 1;
                } elseif ($setting->key === 'pagination_per_page_base') {
                    $min = 11;
                } elseif (in_array($setting->key, ['owner_suggestion_similarity_threshold', 'owner_suggestion_statistics_retention_days', 'owner_suggestion_observation_cleanup_days'])) {
                    $min = 1;
                } elseif ($setting->key === 'owner_suggestion_rebuild_interval_minutes') {
                    $min = 10;
                    $max = 1440;
                }

                if ($setting->key === 'owner_suggestion_similarity_threshold') {
                    $max = 100;
                }

                $component = TextInput::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->numeric()
                    ->integer()
                    ->minValue($min);

                if ($max !== null) {
                    $component->maxValue($max);
                }

                if ($setting->key === 'owner_suggestion_similarity_threshold') {
                    $component->suffix('%');
                }

                $component->required();
            } elseif ($setting->key === 'znuny_queue_host_mappings') {
                $component = app(ZnunyQueueHostMappingSchemaBuilder::class)->buildRepeater($setting, $initialData);
            } elseif ($setting->key === 'znuny_global_queue_exclusion_regexes') {
                $component = \Filament\Forms\Components\Repeater::make($setting->key)
                    ->label($label)
                    ->helperText('Enter regex patterns without delimiters or modifiers. Matching is case-insensitive and UTF-8 aware by default, and is checked against queue Name and FullName. Blank rows are ignored. Invalid regex patterns are ignored and logged.')
                    ->schema([
                        TextInput::make('regex')
                            ->label('Regex pattern')
                            ->placeholder('^Postmaster::')
                            ->helperText("Examples:\n^Postmaster:: hides queues starting with Postmaster::\n^Test hides queues starting with Test\nArchive hides queues containing Archive\n^(Postmaster|Junk):: hides queues starting with Postmaster:: or Junk::"),
                    ])
                    ->addActionLabel('Add regex pattern');
            } elseif ($setting->key === 'znuny_ticket_workspace_active_state_type_ids') {
                $component = Select::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->multiple()
                    ->options([
                        'new' => 'New',
                        'open' => 'Open',
                        'pending_reminder' => 'Pending reminder',
                        'pending_auto' => 'Pending auto',
                        'closed' => 'Closed',
                        'merged' => 'Merged',
                    ])
                    ->required();
            } elseif (in_array($setting->key, ['zabbix_attention_highlight_text_color', 'zabbix_attention_highlight_underline_color'])) {
                $component = Select::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->options([
                        'custom_hex' => 'Custom HEX',
                        'aquamarine' => 'Aquamarine',
                        'white' => 'White',
                        'gray' => 'Gray',
                        'red' => 'Red',
                        'orange' => 'Orange',
                        'amber' => 'Amber',
                        'yellow' => 'Yellow',
                        'lime' => 'Lime',
                        'green' => 'Green',
                        'emerald' => 'Emerald',
                        'cyan' => 'Cyan',
                        'sky' => 'Sky',
                        'blue' => 'Blue',
                        'violet' => 'Violet',
                        'pink' => 'Pink',
                    ])
                    ->disabled(fn (callable $get) => $setting->key === 'zabbix_attention_highlight_underline_color' && $get('zabbix_attention_highlight_underline_style') === 'disabled')
                    ->required()
                    ->live();
            } elseif ($setting->key === 'zabbix_attention_highlight_text_custom_hex') {
                $component = TextInput::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->prefix('#')
                    ->regex('/^[0-9A-Fa-f]{6}$/')
                    ->formatStateUsing(fn (?string $state) => ltrim(strtoupper((string) $state), '#'))
                    ->dehydrateStateUsing(fn (?string $state) => '#'.strtoupper((string) $state))
                    ->disabled(fn (callable $get) => $get('zabbix_attention_highlight_text_color') !== 'custom_hex')
                    ->required()
                    ->live();
            } elseif ($setting->key === 'zabbix_attention_highlight_underline_custom_hex') {
                $component = TextInput::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->prefix('#')
                    ->regex('/^[0-9A-Fa-f]{6}$/')
                    ->formatStateUsing(fn (?string $state) => ltrim(strtoupper((string) $state), '#'))
                    ->dehydrateStateUsing(fn (?string $state) => '#'.strtoupper((string) $state))
                    ->disabled(fn (callable $get) => $get('zabbix_attention_highlight_underline_style') === 'disabled' || $get('zabbix_attention_highlight_underline_color') !== 'custom_hex')
                    ->required()
                    ->live();
            } elseif ($setting->key === 'zabbix_attention_highlight_underline_style') {
                $component = Select::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->options([
                        'disabled' => 'Disabled',
                        'solid' => 'Solid',
                        'dashed' => 'Dashed',
                        'dotted' => 'Dotted',
                        'double' => 'Double',
                        'wavy' => 'Wavy',
                    ])
                    ->required()
                    ->live();
            } elseif ($setting->key === 'zabbix_attention_highlight_underline_thickness') {
                $component = Select::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->options([
                        '1px' => '1px',
                        '2px' => '2px',
                        '3px' => '3px',
                    ])
                    ->disabled(fn (callable $get) => $get('zabbix_attention_highlight_underline_style') === 'disabled')
                    ->required()
                    ->live();
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
            } elseif ($setting->key === 'owner_suggestion_old_weight_coefficient') {
                $component = TextInput::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->numeric()
                    ->minValue(0)
                    ->required();
            } elseif ($setting->key === 'znuny_ticket_default_priority') {
                $component = Select::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->options(function () {
                        try {
                            $options = app(ZnunyCachedLookupService::class)->getTicketPriorities();

                            return empty($options) ? ['3 normal' => '3 normal'] : $options;
                        } catch (\Throwable $e) {
                            return ['3 normal' => '3 normal'];
                        }
                    })
                    ->required();
            } elseif ($setting->key === 'znuny_ticket_default_state') {
                $component = Select::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->options(function () {
                        try {
                            $options = app(ZnunyCachedLookupService::class)->getTicketStates();

                            return empty($options) ? ['new' => 'new'] : $options;
                        } catch (\Throwable $e) {
                            return ['new' => 'new'];
                        }
                    })
                    ->required();
            } elseif ($setting->key === 'znuny_ticket_default_lock') {
                $component = Select::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->options([
                        'lock' => 'Lock',
                        'unlock' => 'Unlock',
                    ])
                    ->required();
            } else {
                $input = TextInput::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->required(! in_array($setting->key, ['zabbix_problem_url_template', 'znuny_ticket_url_template', 'mail_from_name', 'mail_admin_recipients', 'mail_smtp_host', 'mail_smtp_username', 'mail_from_address', 'mail_transport']));

                if (in_array($setting->key, ['zabbix_api_token', 'znuny_password'])) {
                    $input->password()
                        ->revealable()
                        ->placeholder('Leave empty to keep current password')
                        ->required(false);
                }

                $component = $input;
            }

            if ($setting->key === 'mail_smtp_password') {
                $component->password()
                    ->revealable()
                    ->placeholder('Leave empty to keep current password')
                    ->required(false);
            }

            if (in_array($setting->key, ['app_display_timezone', 'pagination_per_page_base'])) {
                $groups['General']['Main'][] = $component;
            } elseif (str_starts_with($setting->key, 'mail_')) {
                if (! in_array($setting->key, self::EXPLICIT_MAIL_KEYS)) {
                    $groups['General']['Mail'][] = $component;
                }
            } elseif (str_starts_with($setting->key, 'scheduled_tasks_')) {
                $groups['General']['Scheduler'][] = $component;
            } elseif (str_starts_with($setting->key, 'owner_suggestion_')) {
                $groups['Statistics'][$setting->key] = $component;
            } elseif (in_array($setting->key, ['cleanup_enabled', 'cleanup_batch_size', 'retention_action_logs_days', 'retention_closed_tickets_days', 'retention_failed_jobs_days', 'retention_resolved_days', 'scheduled_task_logs_retention_days']) || str_starts_with($setting->key, 'retention_') || str_starts_with($setting->key, 'cleanup_') || str_ends_with($setting->key, '_retention_days')) {
                $groups['Retention'][] = $component;
            } elseif (in_array($setting->key, ['zabbix_api_url', 'zabbix_api_token', 'zabbix_api_timeout', 'zabbix_api_verify_ssl', 'zabbix_poll_interval_minutes', 'zabbix_problem_cache_ttl_minutes', 'zabbix_problem_limit', 'zabbix_exclude_suppressed_problems', 'zabbix_problem_url_template'])) {
                $groups['Zabbix']['Connection & Polling'][$setting->key] = $component;
            } elseif (in_array($setting->key, ['zabbix_attention_highlighting_enabled', 'zabbix_attention_highlight_text_color', 'zabbix_attention_highlight_text_custom_hex', 'zabbix_attention_highlight_underline_style', 'zabbix_attention_highlight_underline_color', 'zabbix_attention_highlight_underline_custom_hex', 'zabbix_attention_highlight_underline_thickness'])) {
                $groups['Zabbix']['Problem Highlighting'][$setting->key] = $component;
            } elseif (in_array($setting->key, ['znuny_queue_from_host_regex', 'znuny_customer_user_from_queue_template', 'znuny_queue_host_mappings', 'znuny_manual_ticket_footer', 'linked_ticket_manual_close_default_reason', 'manual_ticket_reopen_note_template', 'znuny_ticket_default_priority', 'znuny_ticket_default_state', 'znuny_ticket_default_lock'])) {
                $groups['Znuny Ticket Defaults'][$setting->key] = $component;
            } elseif (in_array($setting->key, ['znuny_ticket_workspace_enabled', 'znuny_ticket_cache_refresh_interval_minutes', 'znuny_ticket_cache_max_pages_per_run', 'znuny_ticket_cache_ttl_minutes', 'znuny_ticket_cache_default_limit', 'znuny_ticket_workspace_active_state_type_ids', 'znuny_closed_ticket_window_days', 'znuny_closed_ticket_small_sync_interval_minutes'])) {
                $groups['Znuny']['Ticket Workspace'][$setting->key] = $component;
            } elseif (in_array($setting->key, ['znuny_queue_cache_ttl_minutes', 'znuny_agent_cache_ttl_minutes', 'znuny_ticket_snapshot_cache_ttl_minutes']) || str_contains($setting->key, '_cache_')) {
                $groups['Cache'][] = $component;
            } elseif (in_array($setting->key, ['znuny_detailed_sync_audit_enabled', 'zabbix_problem_sync_audit_enabled', 'znuny_ticket_workspace_sync_audit_enabled', 'znuny_closed_ticket_sync_audit_auto_enabled'])) {
                $groups['Audit Log'][] = $component;
            } elseif (in_array($setting->key, ['znuny_linked_ticket_sync_interval_minutes', 'znuny_linked_ticket_sync_batch_size'])) {
                $groups['Znuny']['Linked Tickets'][] = $component;
            } elseif (str_starts_with($setting->key, 'znuny_')) {
                $groups['Znuny'][$setting->key] = $component;
            } elseif (in_array($setting->key, ['default_close_delay_hours', 'default_reopen_window_hours', 'manual_ticket_auto_close_schedule_mode', 'manual_ticket_flap_threshold', 'manual_ticket_extra_flapping_delay_hours'])) {
                $groups['Automation'][$setting->key] = $component;
            } else {
                $groups['Other'][] = $component;
            }
        }

        if (! empty($groups['General'])) {
            $groups['General'] = $this->buildGeneralTabGroups($groups['General']);
        }

        if (! empty($groups['Retention'])) {
            $groups['Retention'] = $this->buildRetentionTabGroups($groups['Retention']);
        }

        if (! empty($groups['Cache'])) {
            $groups['Cache'] = $this->buildCacheTabGroups($groups['Cache']);
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

        if (! empty($groups['Statistics'])) {
            $orderedStatisticsKeys = [
                'owner_suggestion_similarity_threshold',
                'owner_suggestion_statistics_retention_days',
                'owner_suggestion_old_weight_coefficient',
                'owner_suggestion_observation_cleanup_days',
                'owner_suggestion_rebuild_interval_minutes',
            ];

            $orderedStatistics = [];
            foreach ($orderedStatisticsKeys as $key) {
                if (isset($groups['Statistics'][$key])) {
                    $orderedStatistics[] = $groups['Statistics'][$key];
                }
            }

            $statisticsSection = Section::make('Statistics')
                ->description('Configure how owner statistics are collected and retained.')
                ->schema($orderedStatistics)
                ->columns(1);
            $groups['Statistics'] = [$statisticsSection];
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

                if ($setting->type === 'json') {
                    if ($setting->key === 'znuny_queue_host_mappings') {
                        $mappingService = app(ZnunyQueueHostMappingService::class);
                        $newValue = json_encode($mappingService->normalizeMappings($newValue));
                    } elseif ($setting->key === 'znuny_global_queue_exclusion_regexes') {
                        $normalized = [];
                        if (is_array($newValue)) {
                            foreach ($newValue as $item) {
                                if (is_array($item) && isset($item['regex']) && trim($item['regex']) !== '') {
                                    $val = trim($item['regex']);
                                    $found = false;
                                    foreach ($normalized as $existing) {
                                        if ($existing['regex'] === $val) {
                                            $found = true;
                                            break;
                                        }
                                    }
                                    if (! $found) {
                                        $normalized[] = ['regex' => $val];
                                    }
                                }
                            }
                        }
                        $newValue = json_encode($normalized);
                    } else {
                        $newValue = is_array($newValue) ? json_encode($newValue) : $newValue;
                    }
                } elseif ($setting->type === 'boolean') {
                    $newValue = $newValue ? 'true' : 'false';
                } else {
                    $newValue = (string) $newValue;
                }

                $isSecretKey = SettingsService::isSecretKey($setting->key);

                // Skip updating if a password field is submitted as empty
                if ($newValue === '' && $isSecretKey) {
                    if ($setting->key === 'mail_smtp_password' && ($data['mail_smtp_password_clear'] ?? false) === true) {
                        // Fall through to clear it
                    } else {
                        continue;
                    }
                }

                $currentPlaintext = SettingsService::string($setting->key);

                if ($currentPlaintext !== $newValue || ($setting->key === 'mail_smtp_password' && ($data['mail_smtp_password_clear'] ?? false) === true)) {
                    $oldValueToLog = $setting->value;
                    $newValueToLog = $newValue;
                    $valueToStore = $newValue;

                    if ($isSecretKey) {
                        $oldValueToLog = '[redacted]';
                        $newValueToLog = '[redacted]';
                        if ($setting->key === 'mail_smtp_password' && $newValue === '') {
                            $valueToStore = '';
                        } else {
                            $valueToStore = SettingsService::encryptForStorage($setting->key, $newValue);
                        }
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

        if (($data['mail_smtp_password_clear'] ?? false) === true) {
            $this->form->fill(array_merge($this->form->getState(), ['mail_smtp_password_clear' => false]));
        }

        $shouldInvalidateLookupCache = false;
        $shouldInvalidateArticleCache = false;
        $shouldClearZnunyReferenceCaches = false;

        foreach ($changedSettings as $change) {
            if ($change['key'] === 'znuny_lookup_cache_ttl_minutes') {
                $shouldInvalidateLookupCache = true;
            } elseif ($change['key'] === 'znuny_ticket_article_cache_ttl_minutes') {
                $shouldInvalidateArticleCache = true;
            } elseif (str_starts_with($change['key'], 'znuny_')) {
                $shouldClearZnunyReferenceCaches = true;
            }
        }

        if ($shouldInvalidateLookupCache) {
            app(ZnunyCachedLookupService::class)->invalidateCache();
        }

        if ($shouldInvalidateArticleCache) {
            app(ZnunyTicketArticleCacheService::class)->forgetAll();
        }

        if ($shouldClearZnunyReferenceCaches) {
            Cache::forget('znuny_active_agents');
            Cache::forget('znuny.queues');
        }

        app(SettingsAuditLogService::class)->logChanges($changedSettings);

        Notification::make()
            ->title('Settings saved successfully.')
            ->success()
            ->send();
    }

    private function buildZabbixTabGroups(array $z): array
    {
        $connectionFields = $z['Connection & Polling'] ?? [];

        $tabs = [
            Tab::make('Connection')
                ->schema(array_filter([
                    $connectionFields['zabbix_api_url'] ?? null,
                    $connectionFields['zabbix_api_token'] ?? null,
                    $connectionFields['zabbix_api_timeout'] ?? null,
                    $connectionFields['zabbix_api_verify_ssl'] ?? null,
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
                    $connectionFields['zabbix_poll_interval_minutes'] ?? null,
                    $connectionFields['zabbix_problem_cache_ttl_minutes'] ?? null,
                    $connectionFields['zabbix_problem_limit'] ?? null,
                    $connectionFields['zabbix_exclude_suppressed_problems'] ?? null,
                    $connectionFields['zabbix_problem_url_template'] ?? null,
                ]))
                ->columns(1),
        ];

        if (isset($z['Problem Highlighting'])) {
            $ph = $z['Problem Highlighting'];

            $previewBlock = Placeholder::make('problem_highlighting_preview')
                ->label('Live Preview')
                ->content(fn (callable $get) => new HtmlString($this->generateHighlightPreview($get)));

            $tabs[] = Tab::make('Problem Highlighting')
                ->schema(array_filter([
                    $ph['zabbix_attention_highlighting_enabled'] ?? null,
                    $previewBlock,
                    $ph['zabbix_attention_highlight_text_color'] ?? null,
                    $ph['zabbix_attention_highlight_text_custom_hex'] ?? null,
                    $ph['zabbix_attention_highlight_underline_style'] ?? null,
                    $ph['zabbix_attention_highlight_underline_thickness'] ?? null,
                    $ph['zabbix_attention_highlight_underline_color'] ?? null,
                    $ph['zabbix_attention_highlight_underline_custom_hex'] ?? null,
                ]))
                ->columns(1);
        }

        return [
            Tabs::make('ZabbixTabs')
                ->tabs($tabs),
        ];
    }

    private function generateHighlightPreview(callable $get): string
    {
        $text = 'Kreisel fastiv ipmi01[main]';

        if (! $get('zabbix_attention_highlighting_enabled')) {
            return $text;
        }

        $service = app(ZabbixAttentionHighlightStyleService::class);

        $textColor = $service->getHighlightColorHex(
            (string) $get('zabbix_attention_highlight_text_color'),
            (string) $get('zabbix_attention_highlight_text_custom_hex')
        );

        $style = "color: {$textColor};";

        $underlineStyle = (string) $get('zabbix_attention_highlight_underline_style');

        if ($underlineStyle !== 'disabled') {
            $underlineColor = $service->getHighlightColorHex(
                (string) $get('zabbix_attention_highlight_underline_color'),
                (string) $get('zabbix_attention_highlight_underline_custom_hex')
            );
            $thickness = (string) $get('zabbix_attention_highlight_underline_thickness');

            $style .= " text-decoration-line: underline; text-decoration-style: {$underlineStyle}; text-decoration-color: {$underlineColor}; text-decoration-thickness: {$thickness}; text-underline-offset: 4px;";
        }

        return "<span style=\"{$style}\">{$text}</span>";
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

            Tab::make('Excludes')
                ->schema(array_filter([
                    $z['znuny_agent_exclude_logins'] ?? null,
                    $z['znuny_global_queue_exclusion_regexes'] ?? null,
                ]))->columns(1),
        ];

        if (isset($z['Linked Tickets'])) {
            $tabs[] = Tab::make('Linked Tickets')
                ->schema($z['Linked Tickets'])
                ->columns(1);
        }

        if (isset($z['Ticket Workspace'])) {
            $workspaceSchema = [];
            $ws = $z['Ticket Workspace'];

            $coreFields = array_filter([
                $ws['znuny_ticket_workspace_enabled'] ?? null,
                $ws['znuny_ticket_workspace_active_state_type_ids'] ?? null,
            ]);
            if (! empty($coreFields)) {
                $workspaceSchema[] = Section::make('Ticket Workspace')
                    ->description('Core Ticket Workspace behavior.')
                    ->schema($coreFields)->columns(1);
            }

            $activeFields = array_filter([
                $ws['znuny_ticket_cache_refresh_interval_minutes'] ?? null,
                $ws['znuny_ticket_cache_default_limit'] ?? null,
                $ws['znuny_ticket_cache_max_pages_per_run'] ?? null,
                $ws['znuny_ticket_cache_ttl_minutes'] ?? null,
            ]);
            if (! empty($activeFields)) {
                $workspaceSchema[] = Section::make('Active Ticket Cache')
                    ->description('Redis cache warmer settings for active Znuny tickets.')
                    ->schema($activeFields)->columns(1);
            }

            $recentFields = array_filter([
                $ws['znuny_closed_ticket_window_days'] ?? null,
                $ws['znuny_closed_ticket_small_sync_interval_minutes'] ?? null,
            ]);
            if (! empty($recentFields)) {
                $workspaceSchema[] = Section::make('Recent Closed Tickets')
                    ->description('Redis-only recent closed-ticket window configuration.')
                    ->schema($recentFields)->columns(1);
            }

            $tabs[] = Tab::make('Ticket Workspace')
                ->schema($workspaceSchema)
                ->columns(1);
        }

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

                $zd['znuny_manual_ticket_footer'] ?? null,
                $zd['linked_ticket_manual_close_default_reason'] ?? null,
                $zd['manual_ticket_reopen_note_template'] ?? null,
            ]))->columns(1);

        $tabs[] = Tab::make('Advanced Ticket Preset')
            ->schema(array_filter([
                $zd['znuny_ticket_default_priority'] ?? null,
                $zd['znuny_ticket_default_state'] ?? null,
                $zd['znuny_ticket_default_lock'] ?? null,
            ]))->columns(1);

        return [
            Tabs::make('ZnunyTicketDefaultsTabs')->tabs($tabs),
        ];
    }

    private function buildGeneralTabGroups(array $g): array
    {
        $tabs = [];

        if (! empty($g['Main'])) {
            $tabs[] = Tab::make('Main')
                ->schema($this->buildMainTabGroups($g['Main']))
                ->columns(1);
        }

        $mailSchema = [
            Toggle::make('mail_notifications_enabled')
                ->label('Mail Notifications Enabled')
                ->helperText('Enable or disable outgoing mail notifications')
                ->required(),
            ToggleButtons::make('mail_transport')
                ->label('Mail Transport')
                ->helperText('Select the mail transport method.')
                ->options([
                    'sendmail' => 'Server Sendmail',
                    'smtp' => 'External SMTP Server',
                ])
                ->inline()
                ->required()
                ->live(),
            TextInput::make('mail_admin_recipients')
                ->label('Mail Admin Recipients')
                ->helperText('Comma-separated list of admin email addresses to receive system alerts')
                ->required(false),
            TextInput::make('mail_from_address')
                ->label('Mail From Address')
                ->helperText('Global FROM address for outgoing mails')
                ->required(false),
            TextInput::make('mail_from_name')
                ->label('Mail From Name')
                ->helperText('Global FROM name for outgoing mails')
                ->required(false),

            Section::make('Sendmail Configuration')
                ->schema([
                    TextInput::make('mail_sendmail_path')
                        ->label('Mail Sendmail Path')
                        ->helperText('Path to the sendmail binary')
                        ->required(fn (callable $get) => $get('mail_transport') === 'sendmail'),
                ])
                ->hidden(fn (callable $get) => $get('mail_transport') !== 'sendmail')
                ->columns(1),

            Section::make('SMTP Configuration')
                ->schema([
                    TextInput::make('mail_smtp_host')
                        ->label('Mail Smtp Host')
                        ->helperText('SMTP host address')
                        ->required(fn (callable $get) => $get('mail_transport') === 'smtp'),
                    TextInput::make('mail_smtp_port')
                        ->label('Mail Smtp Port')
                        ->helperText('SMTP port')
                        ->numeric()
                        ->integer()
                        ->required(fn (callable $get) => $get('mail_transport') === 'smtp'),
                    TextInput::make('mail_smtp_encryption')
                        ->label('Mail Smtp Encryption')
                        ->helperText('SMTP encryption (none, tls, ssl)')
                        ->required(fn (callable $get) => $get('mail_transport') === 'smtp'),
                    TextInput::make('mail_smtp_username')
                        ->label('Mail Smtp Username')
                        ->helperText('SMTP username')
                        ->required(false),
                    TextInput::make('mail_smtp_password')
                        ->label('Mail Smtp Password')
                        ->helperText('SMTP password')
                        ->password()
                        ->revealable()
                        ->placeholder('Leave empty to keep current password')
                        ->required(false),
                    Toggle::make('mail_smtp_password_clear')
                        ->label('Clear Stored SMTP Password')
                        ->default(false),
                    TextInput::make('mail_smtp_timeout_seconds')
                        ->label('Mail Smtp Timeout Seconds')
                        ->helperText('SMTP timeout in seconds')
                        ->numeric()
                        ->integer()
                        ->required(fn (callable $get) => $get('mail_transport') === 'smtp'),
                ])
                ->hidden(fn (callable $get) => $get('mail_transport') !== 'smtp')
                ->columns(2),

            Actions::make([
                Action::make('testMailConnection')
                    ->label('Send Test Email')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->action('testMailConnectionAction'),
            ]),
        ];

        if (! empty($g['Mail'])) {
            $mailSchema[] = Section::make('Additional Mail Settings')
                ->schema($g['Mail'])
                ->columns(1);
        }

        $tabs[] = Tab::make('Mail')
            ->schema($mailSchema)
            ->columns(1);

        $tabs[] = Tab::make('Scheduler')
            ->schema($this->buildSchedulerTabGroups($g['Scheduler'] ?? []))
            ->columns(1);

        return [
            Tabs::make('GeneralTabs')->tabs($tabs),
        ];
    }

    private function buildSchedulerTabGroups(array $additionalSchedulerSettings): array
    {
        $schema = [
            Section::make('Scheduler Control')
                ->description('Enable or disable processing of scheduled Znuny tasks.')
                ->schema([
                    Toggle::make('scheduled_tasks_enabled')
                        ->label('Scheduler Enabled')
                        ->helperText('Global switch for scheduled Znuny task processing.')
                        ->required(),
                ])
                ->columns(1),

            Section::make('Execution Limits')
                ->description('Control how much work one scheduler command may perform.')
                ->schema([
                    TextInput::make('scheduled_tasks_max_processed_per_run')
                        ->label('Maximum Tasks per Run')
                        ->helperText('Maximum number of scheduled tasks processed sequentially during one command run.')
                        ->numeric()
                        ->integer()
                        ->required(),
                    TextInput::make('scheduled_tasks_command_runtime_seconds')
                        ->label('Command Runtime Limit (seconds)')
                        ->helperText('Maximum time the scheduler processing command may run before it stops accepting more work.')
                        ->numeric()
                        ->integer()
                        ->required(),
                ])
                ->columns(2),

            Section::make('Recovery and Catch-up')
                ->description('Configure temporary pauses and processing of missed scheduled runs.')
                ->schema([
                    TextInput::make('scheduled_tasks_pause_minutes')
                        ->label('Pause After Transient Error (minutes)')
                        ->helperText('How long scheduler processing pauses after a transient connection or service error.')
                        ->numeric()
                        ->integer()
                        ->required(),
                    TextInput::make('scheduled_tasks_missed_run_max_age_days')
                        ->label('Missed Run Catch-up Window (days)')
                        ->helperText('Maximum age of a missed scheduled run that may still be executed by the catch-up process.')
                        ->numeric()
                        ->integer()
                        ->required(),
                ])
                ->columns(2),

            Section::make('Failure Protection')
                ->description('Automatically stop scheduler processing when repeated failures require administrator attention.')
                ->schema([
                    Toggle::make('scheduled_tasks_auto_disable_on_failures')
                        ->label('Auto-disable After Repeated Failures')
                        ->helperText('Disable scheduler processing automatically after the configured number of consecutive failures.')
                        ->required(),
                    TextInput::make('scheduled_tasks_failure_threshold')
                        ->label('Consecutive Failure Threshold')
                        ->helperText('Number of consecutive failures that triggers automatic scheduler disablement.')
                        ->numeric()
                        ->integer()
                        ->required(),
                ])
                ->columns(1),
        ];

        if (! empty($additionalSchedulerSettings)) {
            $schema[] = Section::make('Additional Scheduler Settings')
                ->schema($additionalSchedulerSettings)
                ->columns(1);
        }

        return $schema;
    }

    private function buildMainTabGroups(array $mainComponents): array
    {
        $explicit = [];
        $unmatched = [];

        foreach ($mainComponents as $component) {
            $name = method_exists($component, 'getName') ? $component->getName() : null;
            if ($name === 'app_display_timezone') {
                $explicit['app_display_timezone'] = $component
                    ->label('Display Time Zone')
                    ->helperText('Time zone used only for dates and times shown in the administration interface. Stored timestamps, background processing, and scheduler timing are not changed.');
            } elseif ($name === 'pagination_per_page_base') {
                $explicit['pagination_per_page_base'] = $component
                    ->label('Base Rows per Page')
                    ->helperText('Base number of rows used by paginated tables. Available page-size choices are generated as half of this value rounded up to the nearest multiple of 5, the base value, double the value, and triple the value. For example, 100 produces 50, 100, 200, and 300.');
            } else {
                $unmatched[] = $component;
            }
        }

        $schema = [];

        if (isset($explicit['app_display_timezone']) || isset($explicit['pagination_per_page_base'])) {
            $sectionSchema = [];
            if (isset($explicit['app_display_timezone'])) {
                $sectionSchema[] = $explicit['app_display_timezone'];
            }
            if (isset($explicit['pagination_per_page_base'])) {
                $sectionSchema[] = $explicit['pagination_per_page_base'];
            }

            $schema[] = Section::make('Application Display')
                ->description('Configure how dates, times, and table page sizes are presented in the administration interface.')
                ->schema($sectionSchema)
                ->columns([
                    'default' => 1,
                    'sm' => 2,
                ]);
        }

        if (! empty($unmatched)) {
            $schema[] = Section::make('Additional Application Settings')
                ->schema($unmatched)
                ->columns(1);
        }

        return $schema;
    }

    private function buildRetentionTabGroups(array $retentionComponents): array
    {
        $explicit = [];
        $unmatched = [];

        foreach ($retentionComponents as $component) {
            $name = method_exists($component, 'getName') ? $component->getName() : null;
            if (in_array($name, [
                'cleanup_enabled',
                'cleanup_batch_size',
                'retention_resolved_days',
                'retention_closed_tickets_days',
                'retention_action_logs_days',
                'scheduled_task_logs_retention_days',
                'retention_failed_jobs_days',
            ])) {
                $explicit[$name] = $component;
            } else {
                $unmatched[] = $component;
            }
        }

        if (isset($explicit['cleanup_enabled'])) {
            $explicit['cleanup_enabled']
                ->label('Automatic Local Data Cleanup')
                ->helperText('Enable scheduled cleanup of old local integration records. Disabling this option preserves all retention settings but prevents automatic deletion. This does not delete active Zabbix problems or Znuny tickets.');
        }

        if (isset($explicit['cleanup_batch_size'])) {
            $explicit['cleanup_batch_size']
                ->label('Records per Cleanup Batch')
                ->helperText('Maximum number of records removed from each cleanup category during one cleanup pass. Lower values reduce database load; higher values clear accumulated old data faster.');
        }

        if (isset($explicit['retention_resolved_days'])) {
            $explicit['retention_resolved_days']
                ->label('Resolved Problem History (days)')
                ->helperText('Number of days to keep local history for Zabbix problems after they become resolved. This does not delete problems, events, or history from Zabbix.');
        }

        if (isset($explicit['retention_closed_tickets_days'])) {
            $explicit['retention_closed_tickets_days']
                ->label('Closed Ticket Link History (days)')
                ->helperText('Number of days to keep local integration records and links for closed tickets. This does not delete tickets, articles, or history from Znuny.');
        }

        if (isset($explicit['retention_action_logs_days'])) {
            $explicit['retention_action_logs_days']
                ->label('Action Log Retention (days)')
                ->helperText('Number of days to keep local application action-log records used for operational history, auditing, and troubleshooting.');
        }

        if (isset($explicit['scheduled_task_logs_retention_days'])) {
            $explicit['scheduled_task_logs_retention_days']
                ->label('Scheduled Task Run Log Retention (days)')
                ->helperText('Number of days to keep execution logs for scheduled Znuny task runs. Scheduled task definitions and pending scheduled work are not deleted by this retention setting.');
        }

        if (isset($explicit['retention_failed_jobs_days'])) {
            $explicit['retention_failed_jobs_days']
                ->label('Failed Job Retention (days)')
                ->helperText('Number of days to keep failed background-job records for diagnostics and troubleshooting.');
        }

        $schema = [];

        if (isset($explicit['cleanup_enabled']) || isset($explicit['cleanup_batch_size'])) {
            $section1 = [];
            if (isset($explicit['cleanup_enabled'])) {
                $section1[] = $explicit['cleanup_enabled'];
            }
            if (isset($explicit['cleanup_batch_size'])) {
                $section1[] = $explicit['cleanup_batch_size'];
            }
            $schema[] = Section::make('Cleanup Control')
                ->description('Controls how long this integration keeps local operational records and how scheduled cleanup removes records that exceed the retention periods configured below. These settings affect only local integration data and do not delete data from Zabbix or Znuny.')
                ->schema($section1)
                ->columns([
                    'default' => 1,
                    'sm' => 2,
                ]);
        }

        if (isset($explicit['retention_resolved_days']) || isset($explicit['retention_closed_tickets_days'])) {
            $section2 = [];
            if (isset($explicit['retention_resolved_days'])) {
                $section2[] = $explicit['retention_resolved_days'];
            }
            if (isset($explicit['retention_closed_tickets_days'])) {
                $section2[] = $explicit['retention_closed_tickets_days'];
            }
            $schema[] = Section::make('Integration History')
                ->description('Configure how long local history linking Zabbix problems and Znuny tickets remains available in this integration.')
                ->schema($section2)
                ->columns([
                    'default' => 1,
                    'sm' => 2,
                ]);
        }

        if (isset($explicit['retention_action_logs_days']) || isset($explicit['scheduled_task_logs_retention_days']) || isset($explicit['retention_failed_jobs_days'])) {
            $section3 = [];
            if (isset($explicit['retention_action_logs_days'])) {
                $section3[] = $explicit['retention_action_logs_days'];
            }
            if (isset($explicit['scheduled_task_logs_retention_days'])) {
                $section3[] = $explicit['scheduled_task_logs_retention_days'];
            }
            if (isset($explicit['retention_failed_jobs_days'])) {
                $section3[] = $explicit['retention_failed_jobs_days'];
            }
            $schema[] = Section::make('Logs and Processing Records')
                ->description('Configure how long local operational logs and failed-processing records remain available for auditing and troubleshooting.')
                ->schema($section3)
                ->columns([
                    'default' => 1,
                    'sm' => 3,
                ]);
        }

        if (! empty($unmatched)) {
            $schema[] = Section::make('Additional Retention Settings')
                ->schema($unmatched)
                ->columns(1);
        }

        return $schema;
    }

    private function buildCacheTabGroups(array $cacheComponents): array
    {
        $explicit = [];
        $unmatched = [];

        foreach ($cacheComponents as $component) {
            $name = method_exists($component, 'getName') ? $component->getName() : null;
            if (in_array($name, [
                'znuny_agent_cache_ttl_minutes',
                'znuny_queue_cache_ttl_minutes',
                'znuny_lookup_cache_ttl_minutes',
                'znuny_ticket_article_cache_ttl_minutes',
                'znuny_ticket_snapshot_cache_ttl_minutes',
            ])) {
                $explicit[$name] = $component;
            } else {
                $unmatched[] = $component;
            }
        }

        if (isset($explicit['znuny_agent_cache_ttl_minutes'])) {
            $explicit['znuny_agent_cache_ttl_minutes']
                ->label('Znuny Agent Cache Lifetime (minutes)')
                ->helperText('Configured lifetime for cached active Znuny agent data used by owner selectors and agent-name displays.');
        }

        if (isset($explicit['znuny_queue_cache_ttl_minutes'])) {
            $explicit['znuny_queue_cache_ttl_minutes']
                ->label('Znuny Queue Cache Lifetime (minutes)')
                ->helperText('Configured lifetime for cached Znuny queue data used by queue selectors, queue detection, and queue-mapping validation.');
        }

        if (isset($explicit['znuny_lookup_cache_ttl_minutes'])) {
            $explicit['znuny_lookup_cache_ttl_minutes']
                ->label('Znuny Lookup Cache Lifetime (minutes)')
                ->helperText('How long reusable Znuny lookup data such as owners by queue, CustomerUsers, states, priorities, types, filtered queues, and template or search candidates may be cached. Set to 0 to bypass persistent lookup caching.');
        }

        if (isset($explicit['znuny_ticket_article_cache_ttl_minutes'])) {
            $explicit['znuny_ticket_article_cache_ttl_minutes']
                ->label('Ticket Article Cache Lifetime (minutes)')
                ->helperText('How long Znuny ticket articles fetched for linked tickets may be cached. Set to 0 to bypass persistent ticket article caching.');
        }

        if (isset($explicit['znuny_ticket_snapshot_cache_ttl_minutes'])) {
            $explicit['znuny_ticket_snapshot_cache_ttl_minutes']
                ->label('Linked Ticket Snapshot Cache Lifetime (minutes)')
                ->helperText('Configured lifetime for cached linked-ticket snapshot data. A snapshot may include locally stored Znuny ticket details such as state, owner, queue, priority, and synchronization metadata. This setting does not control Ticket Workspace caching and does not delete local ticket links or data in Znuny.');
        }

        $schema = [];

        if (isset($explicit['znuny_agent_cache_ttl_minutes']) || isset($explicit['znuny_queue_cache_ttl_minutes']) || isset($explicit['znuny_lookup_cache_ttl_minutes'])) {
            $section1 = [];
            if (isset($explicit['znuny_agent_cache_ttl_minutes'])) {
                $section1[] = $explicit['znuny_agent_cache_ttl_minutes'];
            }
            if (isset($explicit['znuny_queue_cache_ttl_minutes'])) {
                $section1[] = $explicit['znuny_queue_cache_ttl_minutes'];
            }
            if (isset($explicit['znuny_lookup_cache_ttl_minutes'])) {
                $section1[] = $explicit['znuny_lookup_cache_ttl_minutes'];
            }
            $schema[] = Section::make('Znuny Reference Data')
                ->description('Configure how long reusable Znuny agent, queue, and lookup reference data may be kept before the application requests updated data from Znuny. Shorter values provide fresher reference data but may increase API requests.')
                ->schema($section1)
                ->columns([
                    'default' => 1,
                    'sm' => 2,
                ]);
        }

        if (isset($explicit['znuny_ticket_snapshot_cache_ttl_minutes']) || isset($explicit['znuny_ticket_article_cache_ttl_minutes'])) {
            $section2 = [];
            if (isset($explicit['znuny_ticket_article_cache_ttl_minutes'])) {
                $section2[] = $explicit['znuny_ticket_article_cache_ttl_minutes'];
            }
            if (isset($explicit['znuny_ticket_snapshot_cache_ttl_minutes'])) {
                $section2[] = $explicit['znuny_ticket_snapshot_cache_ttl_minutes'];
            }

            $schema[] = Section::make('Znuny Linked Ticket Data')
                ->description('Configure caching for Znuny ticket articles and locally stored linked-ticket snapshots. These settings affect read performance and freshness only; they do not delete articles, ticket links, or data in Znuny.')
                ->schema($section2)
                ->columns(1);
        }

        if (! empty($unmatched)) {
            $schema[] = Section::make('Additional Cache Settings')
                ->description('Additional cache-related settings that are not yet assigned to a dedicated Cache section.')
                ->schema($unmatched)
                ->columns(1);
        }

        $schema[] = Section::make('Runtime Cache Maintenance')
            ->description('Clear individual application runtime caches without changing saved settings or clearing unrelated cache scopes.')
            ->schema([
                Actions::make([
                    Action::make('clearSettingsCache')
                        ->label('Clear Settings Cache')
                        ->color('warning')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->modalHeading('Clear Settings Cache?')
                        ->modalDescription('This clears the cached application settings. Saved settings remain unchanged and will be loaded again when needed.')
                        ->modalSubmitActionLabel('Clear Settings Cache')
                        ->action('clearSettingsCacheAction')
                        ->visible(fn () => auth()->user()?->role === 'admin'),
                    Action::make('clearZnunyAgentCache')
                        ->label('Clear Znuny Agent Cache')
                        ->color('warning')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->modalHeading('Clear Znuny Agent Cache?')
                        ->modalDescription('This clears the cached active Znuny agent list. The next agent request may contact Znuny again.')
                        ->modalSubmitActionLabel('Clear Agent Cache')
                        ->action('clearZnunyAgentCacheAction')
                        ->visible(fn () => auth()->user()?->role === 'admin'),
                    Action::make('clearZnunyQueueCache')
                        ->label('Clear Znuny Queue Cache')
                        ->color('warning')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->modalHeading('Clear Znuny Queue Cache?')
                        ->modalDescription('This clears the cached Znuny queue list. The next queue request may contact Znuny again.')
                        ->modalSubmitActionLabel('Clear Queue Cache')
                        ->action('clearZnunyQueueCacheAction')
                        ->visible(fn () => auth()->user()?->role === 'admin'),
                    Action::make('clearZnunyLookupCache')
                        ->label('Clear Znuny Lookup Cache')
                        ->color('warning')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->modalHeading('Clear Znuny Lookup Cache?')
                        ->modalDescription('This invalidates reusable Znuny lookup data such as owners, CustomerUsers, states, priorities, types, queues, and search candidates.')
                        ->modalSubmitActionLabel('Clear Lookup Cache')
                        ->action('clearZnunyLookupCacheAction')
                        ->visible(fn () => auth()->user()?->role === 'admin'),
                    Action::make('clearTicketArticleCache')
                        ->label('Clear Ticket Article Cache')
                        ->color('warning')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->modalHeading('Clear Ticket Article Cache?')
                        ->modalDescription('This invalidates cached Znuny ticket articles used by linked-ticket views. The next article request may contact Znuny again.')
                        ->modalSubmitActionLabel('Clear Article Cache')
                        ->action('clearTicketArticleCacheAction')
                        ->visible(fn () => auth()->user()?->role === 'admin'),
                ]),
            ])
            ->columns(1);

        return $schema;
    }
}
