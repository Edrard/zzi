<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\MailNotificationService;
use App\Services\RuntimeCacheMaintenanceService;
use App\Services\SettingsAuditLogService;
use App\Services\SettingsService;
use App\Services\Support\ApplicationLocaleService;
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
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
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

    private function localizedSettingLabel(string $key, string $fallback): string
    {
        $translationKey = "settings.metadata.{$key}.label";

        return Lang::has($translationKey) ? __($translationKey) : $fallback;
    }

    private function localizedSettingDescription(string $key, ?string $fallback): ?string
    {
        if ($fallback === null) {
            return null;
        }

        $translationKey = "settings.metadata.{$key}.description";

        return Lang::has($translationKey) ? __($translationKey) : $fallback;
    }

    private function localizedSettingOption(string $key, string $rawValue, string $fallback): string
    {
        $translationKey = "settings.metadata.{$key}.options.{$rawValue}";

        return Lang::has($translationKey) ? __($translationKey) : $fallback;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected string $view = 'filament.pages.settings';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.administration');
    }

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('navigation.pages.settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('navigation.pages.settings');
    }

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()->role === 'admin';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('settings.settings_page.actions.save'))
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
                ->title(__('settings.settings_page.notifications.test_email_failed.title'))
                ->body(new HtmlString(
                    '<strong>'.__('settings.settings_page.notifications.test_email_failed.errors_heading').'</strong><br>❌ '.htmlspecialchars($errorResult).'<br>'
                ))
                ->color('danger')
                ->persistent()
                ->send();

            return;
        }

        try {
            app(MailNotificationService::class)->sendTestEmail($data);

            Notification::make()
                ->title(__('settings.settings_page.notifications.test_email_sent.title'))
                ->body(__('settings.settings_page.notifications.test_email_sent.body'))
                ->color('success')
                ->persistent()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title(__('settings.settings_page.notifications.test_email_failed.title'))
                ->body(new HtmlString(
                    '<strong>'.__('settings.settings_page.notifications.test_email_failed.errors_heading').'</strong><br>❌ '.htmlspecialchars($e->getMessage()).'<br>'
                ))
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
                ->title(__('settings.settings_page.notifications.znuny_connection_failed.title'))
                ->body(new HtmlString(
                    '<strong>'.__('settings.settings_page.notifications.znuny_connection_failed.errors_heading').'</strong><br>❌ '.htmlspecialchars($errorResult).'<br>'
                ))
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
            'success' => __('settings.settings_page.notifications.znuny_connection_successful.title'),
            'partial' => __('settings.settings_page.notifications.znuny_connection_partial.title'),
            default => __('settings.settings_page.notifications.znuny_connection_failed.title'),
        };

        $body = '<strong>'.__('settings.settings_page.notifications.znuny_connection_successful.checks_heading').'</strong><br>';
        foreach ($checks as $key => $passed) {
            $icon = $passed ? '✅' : '❌';
            $body .= "{$icon} ".Str::title(str_replace('_', ' ', $key)).'<br>';
        }

        if (! empty($counts)) {
            $body .= '<br><strong>'.__('settings.settings_page.notifications.znuny_connection_successful.counts_heading').'</strong><br>';
            foreach ($counts as $key => $count) {
                $body .= Str::title(str_replace('_', ' ', $key)).": {$count}<br>";
            }
        }

        if (! empty($warnings)) {
            $body .= '<br><strong>'.__('settings.settings_page.notifications.znuny_connection_successful.warnings_heading').'</strong><br>';
            foreach ($warnings as $warning) {
                $body .= '⚠️ '.htmlspecialchars($warning).'<br>';
            }
        }

        if (! empty($errors)) {
            $body .= '<br><strong>'.__('settings.settings_page.notifications.znuny_connection_successful.errors_heading').'</strong><br>';
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
                ->title(__('settings.settings_page.notifications.zabbix_connection_failed.title'))
                ->body(new HtmlString(
                    '<strong>'.__('settings.settings_page.notifications.zabbix_connection_failed.errors_heading').'</strong><br>❌ '.htmlspecialchars($errorResult).'<br>'
                ))
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
                ->title(__('settings.settings_page.notifications.zabbix_connection_successful.title'))
                ->body(__('settings.settings_page.notifications.zabbix_connection_successful.body', ['version' => $version]))
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
                ->title(__('settings.settings_page.notifications.zabbix_connection_failed.title'))
                ->body(new HtmlString(
                    '<strong>'.__('settings.settings_page.notifications.zabbix_connection_failed.errors_heading').'</strong><br>❌ '.htmlspecialchars($e->getMessage()).'<br>'
                ))
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

    private function auditRuntimeCacheMaintenance(
        string $action,
        string $cacheScope,
        string $status,
        ?\Throwable $exception = null
    ): void {
        try {
            $context = [
                'source' => 'settings_cache_tab',
                'cache_scope' => $cacheScope,
                'status' => $status,
            ];

            if ($exception !== null) {
                $context['exception_class'] = get_class($exception);
            }

            AuditLogger::log(
                action: $action,
                entityType: 'settings',
                entityId: null,
                context: $context
            );
        } catch (\Throwable $auditException) {
            report($auditException);
        }
    }

    private function executeCacheMaintenance(callable $serviceCall, string $auditAction, string $cacheScope, string $successTitle, string $successBody, string $failureBody): void
    {
        $this->authorizeRuntimeCacheMaintenance();

        try {
            $serviceCall();

            $this->auditRuntimeCacheMaintenance($auditAction, $cacheScope, 'success');

            Notification::make()
                ->title($successTitle)
                ->body($successBody)
                ->success()
                ->send();
        } catch (\Throwable $e) {
            report($e);

            $this->auditRuntimeCacheMaintenance($auditAction, $cacheScope, 'failed', $e);

            Notification::make()
                ->title(__('settings.settings_page.notifications.cache_clearing_failed.title'))
                ->body($failureBody)
                ->danger()
                ->send();
        }
    }

    public function clearTicketArticleCacheAction(): void
    {
        $this->executeCacheMaintenance(
            fn () => app(RuntimeCacheMaintenanceService::class)->clearTicketArticleCache(),
            'settings.znuny_ticket_article_cache.clear',
            'znuny_ticket_article',
            __('settings.settings_page.notifications.cache_clearing_successful.title_article'),
            __('settings.settings_page.notifications.cache_clearing_successful.body_article'),
            __('settings.settings_page.notifications.cache_clearing_failed.body_article')
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
                    'description' => 'Write summary audit records for scheduled Zabbix problem polling.',
                ],
                'znuny_ticket_workspace_sync_audit_enabled' => [
                    'label' => 'Ticket Workspace Sync Audit',
                    'description' => 'Write summary audit records for scheduled Ticket Workspace cache warming.',
                ],
                'znuny_ticket_url_template' => [
                    'label' => 'Znuny Ticket URL Template',
                    'description' => 'Template used to open a Znuny ticket in the Znuny web UI. Supported placeholders: {ticket_id} for the internal Znuny TicketID. Example: https://znuny.example.com/otrs/index.pl?Action=AgentTicketZoom;TicketID={ticket_id}',
                ],
                'zabbix_problem_url_template' => [
                    'label' => 'Zabbix Problem URL Template',
                    'description' => 'Template used to open a Zabbix problem in the Zabbix web UI. Supported placeholders: {trigger_id}, {event_id}. Example for Zabbix 7.0: https://zabbix.example.com/zabbix.php?show=1&action=problem.view&triggerids%5B%5D={trigger_id} Example with event id: https://zabbix.example.com/tr_events.php?triggerid={trigger_id}&eventid={event_id}',
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
                    'description' => 'Master switch for the entire Ticket Workspace subsystem. When disabled, scheduled and manual synchronization, individual ticket refreshes, and cached ticket reads are blocked. Existing cached data is retained and becomes available again after the feature is re-enabled.',
                ],
                'znuny_ticket_cache_refresh_interval_minutes' => [
                    'label' => 'Active Cache Refresh Interval (Minutes)',
                    'description' => 'How often the scheduled active-ticket cache warmer is allowed to run. The scheduler checks regularly but skips warming until this interval has elapsed; manual refreshes are not limited by this value. Lower values increase Znuny API load.',
                ],
                'znuny_ticket_cache_default_limit' => [
                    'label' => 'Znuny API Fetch Batch Size',
                    'description' => 'Number of active tickets requested from Znuny in each API page during cache warming. This does not control the number of rows displayed in Ticket Workspace. Larger values reduce request count but increase response size and processing load.',
                ],
                'znuny_ticket_cache_max_pages_per_run' => [
                    'label' => 'Max Pages Per Run',
                    'description' => 'Maximum number of Znuny API pages processed during one active-ticket cache warming run. The approximate upper limit per run is Znuny API Fetch Batch Size × Max Pages Per Run; fewer pages are requested when Znuny has no more results.',
                ],
                'znuny_ticket_cache_ttl_minutes' => [
                    'label' => 'Active Ticket Cache TTL (Minutes)',
                    'description' => 'Base Redis lifetime for cached active tickets. The application may automatically increase the effective TTL so cached data does not expire before the next scheduled refresh and UI polling cycle. Increasing this value retains stale active-ticket data longer if synchronization stops.',
                ],

                'znuny_ticket_workspace_active_state_type_ids' => [
                    'label' => 'Active State Types',
                    'description' => 'Select the Znuny state types included in the active ticket working set. These values are state type names, not numeric IDs. Changes apply to the next active-ticket cache refresh.',
                ],
                'znuny_closed_ticket_window_days' => [
                    'label' => 'Closed Ticket Creation Window (Days)',
                    'description' => 'Closed tickets are cached only when their Created timestamp falls within this number of days. The window is not based on the actual close time because Znuny does not provide a sufficiently reliable close timestamp for this workflow. Reducing the value does not immediately remove entries already retained in Redis; cached entries expire naturally and may remain physically stored for up to six times this window.',
                ],
                'znuny_closed_ticket_small_sync_interval_minutes' => [
                    'label' => 'Recent Closed Tickets Sync Interval (Minutes)',
                    'description' => 'How often the scheduled small synchronization checks Znuny for recently changed closed tickets and refreshes the closed-ticket cache. Only tickets whose Created timestamp falls inside the configured creation window are stored. Lower values increase Znuny API load, and synchronization does not run while Ticket Workspace is disabled.',
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

            $label = $this->localizedSettingLabel($setting->key, $label);
            $description = $this->localizedSettingDescription($setting->key, $description);

            $component = null;

            if ($setting->key === 'znuny_agent_exclude_logins') {
                $component = Textarea::make($setting->key)
                    ->label($label)
                    ->helperText(__('settings.settings_page.fields.znuny_agent_exclude_logins.description'))
                    ->required(false)
                    ->rows(4);
            } elseif ($setting->key === 'znuny_manual_ticket_footer') {
                $component = Textarea::make($setting->key)
                    ->label($label)
                    ->helperText($description)
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
                    ->label(__('settings.settings_page.fields.znuny_queue_from_host_regex.label'))
                    ->helperText(__('settings.settings_page.fields.znuny_queue_from_host_regex.description'))
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
                    ->label(__('settings.settings_page.fields.znuny_customer_user_from_queue_template.label'))
                    ->helperText(__('settings.settings_page.fields.znuny_customer_user_from_queue_template.description'))
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

                if ($setting->key === 'cleanup_batch_size' || $setting->key === 'znuny_linked_ticket_sync_interval_minutes' || $setting->key === 'znuny_closed_ticket_window_days' || $setting->key === 'znuny_closed_ticket_small_sync_interval_minutes' || $setting->key === 'znuny_ticket_cache_max_pages_per_run') {
                    $min = 1;
                } elseif ($setting->key === 'pagination_per_page_base') {
                    $min = 11;
                } elseif (in_array($setting->key, ['owner_suggestion_similarity_threshold', 'owner_suggestion_statistics_retention_days', 'owner_suggestion_observation_cleanup_days'])) {
                    $min = 1;
                } elseif ($setting->key === 'owner_suggestion_rebuild_interval_minutes') {
                    $min = 10;
                    $max = 1440;
                } elseif ($setting->key === 'znuny_inline_image_cache_ttl_minutes') {
                    $min = 1;
                    $max = 10080;
                } elseif ($setting->key === 'znuny_inline_image_warmer_interval_minutes') {
                    $min = 1;
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
                    ->helperText(__('settings.settings_page.fields.znuny_global_queue_exclusion_regexes.helper_text'))
                    ->schema([
                        TextInput::make('regex')
                            ->label(__('settings.settings_page.fields.znuny_global_queue_exclusion_regexes.columns.regex_pattern'))
                            ->placeholder(__('settings.settings_page.fields.znuny_global_queue_exclusion_regexes.placeholders.regex_pattern'))
                            ->helperText(__('settings.settings_page.fields.znuny_global_queue_exclusion_regexes.examples')),
                    ])
                    ->addActionLabel(__('settings.settings_page.fields.znuny_global_queue_exclusion_regexes.add_action_label'));
            } elseif ($setting->key === 'znuny_ticket_workspace_active_state_type_ids') {
                $component = Select::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->multiple()
                    ->options([
                        'new' => $this->localizedSettingOption($setting->key, 'new', 'New'),
                        'open' => $this->localizedSettingOption($setting->key, 'open', 'Open'),
                        'pending_reminder' => $this->localizedSettingOption($setting->key, 'pending_reminder', 'Pending reminder'),
                        'pending_auto' => $this->localizedSettingOption($setting->key, 'pending_auto', 'Pending auto'),
                        'closed' => $this->localizedSettingOption($setting->key, 'closed', 'Closed'),
                        'merged' => $this->localizedSettingOption($setting->key, 'merged', 'Merged'),
                    ])
                    ->required();
            } elseif (in_array($setting->key, ['zabbix_attention_highlight_text_color', 'zabbix_attention_highlight_underline_color'])) {
                $component = Select::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->options([
                        'custom_hex' => $this->localizedSettingOption($setting->key, 'custom_hex', 'Custom HEX'),
                        'aquamarine' => $this->localizedSettingOption($setting->key, 'aquamarine', 'Aquamarine'),
                        'white' => $this->localizedSettingOption($setting->key, 'white', 'White'),
                        'gray' => $this->localizedSettingOption($setting->key, 'gray', 'Gray'),
                        'red' => $this->localizedSettingOption($setting->key, 'red', 'Red'),
                        'orange' => $this->localizedSettingOption($setting->key, 'orange', 'Orange'),
                        'amber' => $this->localizedSettingOption($setting->key, 'amber', 'Amber'),
                        'yellow' => $this->localizedSettingOption($setting->key, 'yellow', 'Yellow'),
                        'lime' => $this->localizedSettingOption($setting->key, 'lime', 'Lime'),
                        'green' => $this->localizedSettingOption($setting->key, 'green', 'Green'),
                        'emerald' => $this->localizedSettingOption($setting->key, 'emerald', 'Emerald'),
                        'cyan' => $this->localizedSettingOption($setting->key, 'cyan', 'Cyan'),
                        'sky' => $this->localizedSettingOption($setting->key, 'sky', 'Sky'),
                        'blue' => $this->localizedSettingOption($setting->key, 'blue', 'Blue'),
                        'violet' => $this->localizedSettingOption($setting->key, 'violet', 'Violet'),
                        'pink' => $this->localizedSettingOption($setting->key, 'pink', 'Pink'),
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
                        'disabled' => $this->localizedSettingOption($setting->key, 'disabled', 'Disabled'),
                        'solid' => $this->localizedSettingOption($setting->key, 'solid', 'Solid'),
                        'dashed' => $this->localizedSettingOption($setting->key, 'dashed', 'Dashed'),
                        'dotted' => $this->localizedSettingOption($setting->key, 'dotted', 'Dotted'),
                        'double' => $this->localizedSettingOption($setting->key, 'double', 'Double'),
                        'wavy' => $this->localizedSettingOption($setting->key, 'wavy', 'Wavy'),
                    ])
                    ->required()
                    ->live();
            } elseif ($setting->key === 'zabbix_attention_highlight_underline_thickness') {
                $component = Select::make($setting->key)
                    ->label($label)
                    ->helperText($description)
                    ->options([
                        '1px' => $this->localizedSettingOption($setting->key, '1px', '1px'),
                        '2px' => $this->localizedSettingOption($setting->key, '2px', '2px'),
                        '3px' => $this->localizedSettingOption($setting->key, '3px', '3px'),
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
                    ->helperText($this->localizedSettingDescription(
                        'manual_ticket_auto_close_schedule_mode',
                        'disabled: scheduler will not auto-close manual tickets; dry_run: scheduler logs what would be closed without changing Znuny; execute: scheduler closes eligible manual tickets using the verified /TicketClose workflow.',
                    ))
                    ->options([
                        'disabled' => $this->localizedSettingOption($setting->key, 'disabled', 'Disabled'),
                        'dry_run' => $this->localizedSettingOption($setting->key, 'dry_run', 'Dry Run'),
                        'execute' => $this->localizedSettingOption($setting->key, 'execute', 'Execute'),
                    ])
                    ->required();
            } elseif ($setting->key === 'app_display_timezone') {
                $component = Select::make($setting->key)
                    ->label($label)
                    ->helperText(__('settings.settings_page.fields.app_display_timezone.description'))
                    ->options(array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()))
                    ->searchable()
                    ->required();
            } elseif ($setting->key === 'ui_locale') {
                $component = Select::make($setting->key)
                    ->label(__('settings.general.main.ui_locale.label'))
                    ->helperText(__('settings.general.main.ui_locale.helper_text'))
                    ->options(fn () => app(ApplicationLocaleService::class)->options())
                    ->in(fn () => app(ApplicationLocaleService::class)->supportedLocales())
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
                        'lock' => $this->localizedSettingOption($setting->key, 'lock', 'Lock'),
                        'unlock' => $this->localizedSettingOption($setting->key, 'unlock', 'Unlock'),
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
                        ->placeholder(__('settings.settings_page.fields.'.$setting->key.'.placeholder'))
                        ->required(false);
                }

                $component = $input;
            }

            if ($setting->key === 'mail_smtp_password') {
                $component->password()
                    ->revealable()
                    ->placeholder(__('settings.settings_page.fields.mail_smtp_password.placeholder'))
                    ->required(false);
            }

            if (in_array($setting->key, ['app_display_timezone', 'pagination_per_page_base', 'ui_locale'])) {
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
            } elseif (in_array($setting->key, ['znuny_inline_image_warmer_enabled', 'znuny_inline_image_cache_ttl_minutes', 'znuny_inline_image_warmer_interval_minutes'])) {
                $groups['Znuny']['Ticket Workspace'][$setting->key] = $component;
            } elseif (in_array($setting->key, ['znuny_inline_image_warmer_batch_size', 'znuny_inline_image_warmer_hot_percentage'])) {
                continue;
            } elseif (in_array($setting->key, ['znuny_ticket_snapshot_cache_ttl_minutes', 'znuny_prewarm_queues_interval_minutes', 'znuny_prewarm_agents_interval_minutes', 'znuny_prewarm_lookups_interval_minutes', 'znuny_prewarm_customer_users_interval_minutes']) || str_contains($setting->key, '_cache_')) {
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

            $statisticsSection = Section::make('statistics')
                ->heading(__('settings.settings_page.sections.statistics.heading'))
                ->description(__('settings.settings_page.sections.statistics.description'))
                ->schema($orderedStatistics)
                ->columns(1);
            $groups['Statistics'] = [$statisticsSection];
        }

        if (! empty($groups['Audit Log'])) {
            $groups['Audit Log'] = [
                Section::make('audit_logging')
                    ->heading(__('settings.settings_page.sections.audit_logging.heading'))
                    ->description(__('settings.settings_page.sections.audit_logging.description'))
                    ->schema(array_values($groups['Audit Log']))
                    ->columns(1),
            ];
        }

        if (! empty($groups['Automation'])) {
            $groups['Automation'] = [
                Section::make('ticket_automation')
                    ->heading(__('settings.settings_page.sections.ticket_automation.heading'))
                    ->description(__('settings.settings_page.sections.ticket_automation.description'))
                    ->schema(array_values($groups['Automation']))
                    ->columns(1),
            ];
        }

        $tabs = [];
        foreach ($groups as $groupName => $components) {
            if (! empty($components)) {
                $tabId = Str::snake($groupName);
                $tabs[] = Tab::make($tabId)
                    ->label(__('settings.settings_page.tabs.'.$tabId))
                    ->schema($components)
                    ->columns(1);
            }
        }

        $formComponents = [
            Tabs::make('SettingsTabs')
                ->tabs($tabs),
            Actions::make([
                Action::make('saveBottom')
                    ->label(__('settings.settings_page.actions.save'))
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

        $localeService = app(ApplicationLocaleService::class);
        $previousLocale = $localeService->resolve();

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

        $shouldInvalidateArticleCache = false;

        foreach ($changedSettings as $change) {
            if ($change['key'] === 'znuny_ticket_article_cache_ttl_minutes') {
                $shouldInvalidateArticleCache = true;
            }
        }

        if ($shouldInvalidateArticleCache) {
            app(ZnunyTicketArticleCacheService::class)->forgetAll();
        }

        app(SettingsAuditLogService::class)->logChanges($changedSettings);

        $localeService->apply();
        $newLocale = $localeService->resolve();

        Notification::make()
            ->title(__('settings.settings_page.notifications.settings_saved.title'))
            ->success()
            ->send();

        if ($newLocale !== $previousLocale) {
            $this->redirect(static::getUrl(), navigate: false);
        }
    }

    private function buildZabbixTabGroups(array $z): array
    {
        $connectionFields = $z['Connection & Polling'] ?? [];

        $tabs = [
            Tab::make('connection')
                ->label(__('settings.settings_page.tabs.connection'))
                ->schema(array_filter([
                    $connectionFields['zabbix_api_url'] ?? null,
                    $connectionFields['zabbix_api_token'] ?? null,
                    $connectionFields['zabbix_api_timeout'] ?? null,
                    $connectionFields['zabbix_api_verify_ssl'] ?? null,
                    Actions::make([
                        Action::make('testZabbixConnection')
                            ->label(__('settings.settings_page.actions.test_zabbix_api.label'))
                            ->icon('heroicon-o-signal')
                            ->color('info')
                            ->action('testZabbixConnectionAction'),
                    ]),
                    Placeholder::make('zabbix_tester_help')
                        ->hiddenLabel()
                        ->content(__('settings.settings_page.actions.test_zabbix_api.description')),
                ]))
                ->columns(1),
            Tab::make('problem_handling_and_ui')
                ->label(__('settings.settings_page.tabs.problem_handling_and_ui'))
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
                ->label(__('settings.settings_page.fields.problem_highlighting_preview.label'))
                ->content(fn (callable $get) => new HtmlString($this->generateHighlightPreview($get)));

            $tabs[] = Tab::make('problem_highlighting')
                ->label(__('settings.settings_page.tabs.problem_highlighting'))
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
        $text = __('settings.settings_page.fields.problem_highlighting_preview.sample');

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
                ->label(__('settings.settings_page.actions.test_znuny_api.label'))
                ->icon('heroicon-o-signal')
                ->color('info')
                ->action('testZnunyConnectionAction'),
        ]);
    }

    private function getZnunyConnectionTestHelperPlaceholder(string $name): Placeholder
    {
        return Placeholder::make($name)
            ->hiddenLabel()
            ->content(__('settings.settings_page.actions.test_znuny_api.description'));
    }

    private function buildZnunyTabGroups(array $z): array
    {
        $tabs = [
            Tab::make('credentials')
                ->label(__('settings.settings_page.tabs.credentials'))
                ->schema(array_filter([
                    $z['znuny_username'] ?? null,
                    $z['znuny_password'] ?? null,
                    $this->getZnunyConnectionTestAction('testZnunyConnection_Credentials'),
                    $this->getZnunyConnectionTestHelperPlaceholder('tester_help_Credentials'),
                ]))->columns(1),

            Tab::make('endpoints_and_connection')
                ->label(__('settings.settings_page.tabs.endpoints_and_connection'))
                ->schema(array_filter([
                    $z['znuny_api_url'] ?? null,
                    $z['znuny_web_url'] ?? null,
                    $z['znuny_ticket_url_template'] ?? null,
                    $z['znuny_api_verify_ssl'] ?? null,
                    $z['znuny_api_timeout'] ?? null,
                    $this->getZnunyConnectionTestAction('testZnunyConnection_Endpoints'),
                    $this->getZnunyConnectionTestHelperPlaceholder('tester_help_Endpoints'),
                ]))->columns(1),

            Tab::make('excludes')
                ->label(__('settings.settings_page.tabs.excludes'))
                ->schema(array_filter([
                    $z['znuny_agent_exclude_logins'] ?? null,
                    $z['znuny_global_queue_exclusion_regexes'] ?? null,
                ]))->columns(1),
        ];

        if (isset($z['Linked Tickets'])) {
            $tabs[] = Tab::make('linked_tickets')
                ->label(__('settings.settings_page.tabs.linked_tickets'))
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
                $workspaceSchema[] = Section::make('ticket_workspace')
                    ->heading(__('settings.settings_page.sections.ticket_workspace.heading'))
                    ->description(__('settings.settings_page.sections.ticket_workspace.description'))
                    ->schema($coreFields)->columns(1);
            }

            $activeFields = array_filter([
                $ws['znuny_ticket_cache_refresh_interval_minutes'] ?? null,
                $ws['znuny_ticket_cache_default_limit'] ?? null,
                $ws['znuny_ticket_cache_max_pages_per_run'] ?? null,
                $ws['znuny_ticket_cache_ttl_minutes'] ?? null,
            ]);
            if (! empty($activeFields)) {
                $workspaceSchema[] = Section::make('active_ticket_cache')
                    ->heading(__('settings.settings_page.sections.active_ticket_cache.heading'))
                    ->description(__('settings.settings_page.sections.active_ticket_cache.description'))
                    ->schema($activeFields)->columns(1);
            }

            $recentFields = array_filter([
                $ws['znuny_closed_ticket_window_days'] ?? null,
                $ws['znuny_closed_ticket_small_sync_interval_minutes'] ?? null,
            ]);
            if (! empty($recentFields)) {
                $workspaceSchema[] = Section::make('recent_closed_tickets')
                    ->heading(__('settings.settings_page.sections.recent_closed_tickets.heading'))
                    ->description(__('settings.settings_page.sections.recent_closed_tickets.description'))
                    ->schema($recentFields)->columns(1);
            }

            $inlineImageFields = array_filter([
                $ws['znuny_inline_image_warmer_enabled'] ?? null,
                $ws['znuny_inline_image_cache_ttl_minutes'] ?? null,
                $ws['znuny_inline_image_warmer_interval_minutes'] ?? null,
            ]);
            if (! empty($inlineImageFields)) {
                $workspaceSchema[] = Section::make('inline_images')
                    ->heading(__('settings.settings_page.sections.inline_images.heading'))
                    ->schema($inlineImageFields)->columns(1);
            }

            $tabs[] = Tab::make('ticket_workspace')
                ->label(__('settings.settings_page.tabs.ticket_workspace'))
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
            $tabs[] = Tab::make('queue_host_prefix_mappings')
                ->label(__('settings.settings_page.tabs.queue_host_prefix_mappings'))
                ->schema([
                    Section::make('queue_host_prefix_mappings')
                        ->heading(__('settings.settings_page.sections.queue_host_prefix_mappings.heading'))
                        ->description(__('settings.settings_page.sections.queue_host_prefix_mappings.description'))
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

        $tabs[] = Tab::make('ticket_default_rules')
            ->label(__('settings.settings_page.tabs.ticket_default_rules'))
            ->schema(array_filter([
                $zd['znuny_queue_from_host_regex'] ?? null,
                $zd['znuny_customer_user_from_queue_template'] ?? null,

                $this->getLoadLocalizedTicketTemplatesAction(),

                $zd['znuny_manual_ticket_footer'] ?? null,
                $zd['linked_ticket_manual_close_default_reason'] ?? null,
                $zd['manual_ticket_reopen_note_template'] ?? null,
            ]))->columns(1);

        $tabs[] = Tab::make('advanced_ticket_preset')
            ->label(__('settings.settings_page.tabs.advanced_ticket_preset'))
            ->schema(array_filter([
                $zd['znuny_ticket_default_priority'] ?? null,
                $zd['znuny_ticket_default_state'] ?? null,
                $zd['znuny_ticket_default_lock'] ?? null,
            ]))->columns(1);

        return [
            Tabs::make('ZnunyTicketDefaultsTabs')->tabs($tabs),
        ];
    }

    private function getLoadLocalizedTicketTemplatesAction(): Actions
    {
        return Actions::make([
            Action::make('loadLocalizedTicketTemplates')
                ->label(__('settings.ticket_template_presets.action.label'))
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading(__('settings.ticket_template_presets.action.modal_heading'))
                ->modalDescription(__('settings.ticket_template_presets.action.modal_description'))
                ->modalSubmitActionLabel(__('settings.ticket_template_presets.action.confirm'))
                ->action(function () {
                    if (auth()->user()->role !== 'admin') {
                        abort(403, 'Only admins can modify settings.');
                    }

                    $locale = app()->getLocale();
                    if (! in_array($locale, ['en', 'uk'], true)) {
                        $locale = config('app.fallback_locale', 'en');
                    }

                    $keysToUpdate = [
                        'znuny_manual_ticket_footer',
                        'linked_ticket_manual_close_default_reason',
                        'manual_ticket_reopen_note_template',
                    ];

                    $newValues = [];
                    foreach ($keysToUpdate as $key) {
                        $newValues[$key] = __("settings.ticket_template_presets.defaults.{$key}", [], $locale);
                    }

                    $changedSettings = [];
                    $settings = Setting::whereIn('key', $keysToUpdate)->get();

                    DB::transaction(function () use ($settings, $newValues, &$changedSettings) {
                        foreach ($settings as $setting) {
                            $newValue = $newValues[$setting->key] ?? null;
                            if ($newValue !== null) {
                                $currentPlaintext = SettingsService::string($setting->key);
                                if ($currentPlaintext !== $newValue) {
                                    $changedSettings[] = [
                                        'key' => $setting->key,
                                        'old_value' => $setting->value,
                                        'new_value' => $newValue,
                                    ];
                                    $setting->update(['value' => $newValue]);
                                }
                            }
                        }
                    });

                    if (! empty($changedSettings)) {
                        SettingsService::clearAllCaches();
                        app(SettingsAuditLogService::class)->logChanges($changedSettings);
                    }

                    $this->data = array_replace($this->data ?? [], $newValues);

                    $languageName = __('common.'.$locale, [], $locale);

                    Notification::make()
                        ->title(__('settings.ticket_template_presets.notifications.saved_title'))
                        ->body(__('settings.ticket_template_presets.notifications.saved_body', ['language' => $languageName]))
                        ->success()
                        ->send();
                }),
        ])->key('localized-ticket-template-presets-actions');
    }

    private function buildGeneralTabGroups(array $g): array
    {
        $tabs = [];

        if (! empty($g['Main'])) {
            $tabs[] = Tab::make('main')
                ->label(__('settings.settings_page.tabs.main'))
                ->schema($this->buildMainTabGroups($g['Main']))
                ->columns(1);
        }

        $mailSchema = [
            Toggle::make('mail_notifications_enabled')
                ->label($this->localizedSettingLabel('mail_notifications_enabled', 'Mail Notifications Enabled'))
                ->helperText($this->localizedSettingDescription('mail_notifications_enabled', 'Enable or disable outgoing mail notifications'))
                ->required(),
            ToggleButtons::make('mail_transport')
                ->label($this->localizedSettingLabel('mail_transport', 'Mail Transport'))
                ->helperText($this->localizedSettingDescription('mail_transport', 'Select the mail transport method.'))
                ->options([
                    'sendmail' => $this->localizedSettingOption('mail_transport', 'sendmail', 'Server Sendmail'),
                    'smtp' => $this->localizedSettingOption('mail_transport', 'smtp', 'External SMTP Server'),
                ])
                ->inline()
                ->required()
                ->live(),
            TextInput::make('mail_admin_recipients')
                ->label($this->localizedSettingLabel('mail_admin_recipients', 'Mail Admin Recipients'))
                ->helperText($this->localizedSettingDescription('mail_admin_recipients', 'Comma-separated list of admin email addresses to receive system alerts'))
                ->required(false),
            TextInput::make('mail_from_address')
                ->label($this->localizedSettingLabel('mail_from_address', 'Mail From Address'))
                ->helperText($this->localizedSettingDescription('mail_from_address', 'Global FROM address for outgoing mails'))
                ->required(false),
            TextInput::make('mail_from_name')
                ->label($this->localizedSettingLabel('mail_from_name', 'Mail From Name'))
                ->helperText($this->localizedSettingDescription('mail_from_name', 'Global FROM name for outgoing mails'))
                ->required(false),

            Section::make('sendmail_configuration')
                ->heading(__('settings.settings_page.sections.sendmail_configuration.heading'))
                ->schema([
                    TextInput::make('mail_sendmail_path')
                        ->label($this->localizedSettingLabel('mail_sendmail_path', 'Mail Sendmail Path'))
                        ->helperText($this->localizedSettingDescription('mail_sendmail_path', 'Path to the sendmail binary'))
                        ->required(
                            fn (callable $get): bool => (bool) $get('mail_notifications_enabled')
                                && $get('mail_transport') === 'sendmail'
                        ),
                ])
                ->hidden(fn (callable $get) => $get('mail_transport') !== 'sendmail')
                ->columns(1),

            Section::make('smtp_configuration')
                ->heading(__('settings.settings_page.sections.smtp_configuration.heading'))
                ->schema([
                    TextInput::make('mail_smtp_host')
                        ->label($this->localizedSettingLabel('mail_smtp_host', 'Mail Smtp Host'))
                        ->helperText($this->localizedSettingDescription('mail_smtp_host', 'SMTP host address'))
                        ->required(
                            fn (callable $get): bool => (bool) $get('mail_notifications_enabled')
                                && $get('mail_transport') === 'smtp'
                        ),
                    TextInput::make('mail_smtp_port')
                        ->label($this->localizedSettingLabel('mail_smtp_port', 'Mail Smtp Port'))
                        ->helperText($this->localizedSettingDescription('mail_smtp_port', 'SMTP port'))
                        ->numeric()
                        ->integer()
                        ->required(
                            fn (callable $get): bool => (bool) $get('mail_notifications_enabled')
                                && $get('mail_transport') === 'smtp'
                        ),
                    TextInput::make('mail_smtp_encryption')
                        ->label($this->localizedSettingLabel('mail_smtp_encryption', 'Mail Smtp Encryption'))
                        ->helperText($this->localizedSettingDescription('mail_smtp_encryption', 'SMTP encryption (none, tls, ssl)'))
                        ->required(
                            fn (callable $get): bool => (bool) $get('mail_notifications_enabled')
                                && $get('mail_transport') === 'smtp'
                        ),
                    TextInput::make('mail_smtp_username')
                        ->label($this->localizedSettingLabel('mail_smtp_username', 'Mail Smtp Username'))
                        ->helperText($this->localizedSettingDescription('mail_smtp_username', 'SMTP username'))
                        ->required(false),
                    TextInput::make('mail_smtp_password')
                        ->label($this->localizedSettingLabel('mail_smtp_password', 'Mail Smtp Password'))
                        ->helperText($this->localizedSettingDescription('mail_smtp_password', 'SMTP password'))
                        ->password()
                        ->revealable()
                        ->placeholder(__('settings.settings_page.fields.mail_smtp_password.placeholder'))
                        ->required(false),
                    Toggle::make('mail_smtp_password_clear')
                        ->label(Lang::has('settings.settings_page.fields.mail_smtp_password_clear.label') ? __('settings.settings_page.fields.mail_smtp_password_clear.label') : 'Clear Stored SMTP Password')
                        ->default(false),
                    TextInput::make('mail_smtp_timeout_seconds')
                        ->label($this->localizedSettingLabel('mail_smtp_timeout_seconds', 'Mail Smtp Timeout Seconds'))
                        ->helperText($this->localizedSettingDescription('mail_smtp_timeout_seconds', 'SMTP timeout in seconds'))
                        ->numeric()
                        ->integer()
                        ->required(fn (callable $get) => $get('mail_transport') === 'smtp'),
                ])
                ->hidden(fn (callable $get) => $get('mail_transport') !== 'smtp')
                ->columns(2),

            Actions::make([
                Action::make('testMailConnection')
                    ->label(__('settings.settings_page.actions.send_test_email.label'))
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->action('testMailConnectionAction'),
            ]),
        ];

        if (! empty($g['Mail'])) {
            $mailSchema[] = Section::make('additional_mail_settings')
                ->heading(__('settings.settings_page.sections.additional_mail_settings.heading'))
                ->schema($g['Mail'])
                ->columns(1);
        }

        $tabs[] = Tab::make('mail')
            ->label(__('settings.settings_page.tabs.mail'))
            ->schema($mailSchema)
            ->columns(1);

        $tabs[] = Tab::make('scheduler')
            ->label(__('settings.settings_page.tabs.scheduler'))
            ->schema($this->buildSchedulerTabGroups($g['Scheduler'] ?? []))
            ->columns(1);

        return [
            Tabs::make('GeneralTabs')->tabs($tabs),
        ];
    }

    private function buildSchedulerTabGroups(array $additionalSchedulerSettings): array
    {
        $schema = [
            Section::make('scheduler_control')
                ->heading(__('settings.settings_page.sections.scheduler_control.heading'))
                ->description(__('settings.settings_page.sections.scheduler_control.description'))
                ->schema([
                    Toggle::make('scheduled_tasks_enabled')
                        ->label($this->localizedSettingLabel('scheduled_tasks_enabled', 'Scheduler Enabled'))
                        ->helperText($this->localizedSettingDescription('scheduled_tasks_enabled', 'Global switch for scheduled Znuny task processing.'))
                        ->required(),
                ])
                ->columns(1),

            Section::make('execution_limits')
                ->heading(__('settings.settings_page.sections.execution_limits.heading'))
                ->description(__('settings.settings_page.sections.execution_limits.description'))
                ->schema([
                    TextInput::make('scheduled_tasks_max_processed_per_run')
                        ->label($this->localizedSettingLabel('scheduled_tasks_max_processed_per_run', 'Maximum Tasks per Run'))
                        ->helperText($this->localizedSettingDescription('scheduled_tasks_max_processed_per_run', 'Maximum number of scheduled tasks processed sequentially during one command run.'))
                        ->numeric()
                        ->integer()
                        ->required(),
                    TextInput::make('scheduled_tasks_command_runtime_seconds')
                        ->label($this->localizedSettingLabel('scheduled_tasks_command_runtime_seconds', 'Command Runtime Limit (seconds)'))
                        ->helperText($this->localizedSettingDescription('scheduled_tasks_command_runtime_seconds', 'Maximum time the scheduler processing command may run before it stops accepting more work.'))
                        ->numeric()
                        ->integer()
                        ->required(),
                ])
                ->columns(2),

            Section::make('recovery_and_catch_up')
                ->heading(__('settings.settings_page.sections.recovery_and_catch_up.heading'))
                ->description(__('settings.settings_page.sections.recovery_and_catch_up.description'))
                ->schema([
                    TextInput::make('scheduled_tasks_pause_minutes')
                        ->label($this->localizedSettingLabel('scheduled_tasks_pause_minutes', 'Pause After Transient Error (minutes)'))
                        ->helperText($this->localizedSettingDescription('scheduled_tasks_pause_minutes', 'How long scheduler processing pauses after a transient connection or service error.'))
                        ->numeric()
                        ->integer()
                        ->required(),
                    TextInput::make('scheduled_tasks_missed_run_max_age_days')
                        ->label($this->localizedSettingLabel('scheduled_tasks_missed_run_max_age_days', 'Missed Run Catch-up Window (days)'))
                        ->helperText($this->localizedSettingDescription('scheduled_tasks_missed_run_max_age_days', 'Maximum age of a missed scheduled run that may still be executed by the catch-up process.'))
                        ->numeric()
                        ->integer()
                        ->required(),
                ])
                ->columns(2),

            Section::make('failure_protection')
                ->heading(__('settings.settings_page.sections.failure_protection.heading'))
                ->description(__('settings.settings_page.sections.failure_protection.description'))
                ->schema([
                    Toggle::make('scheduled_tasks_auto_disable_on_failures')
                        ->label($this->localizedSettingLabel('scheduled_tasks_auto_disable_on_failures', 'Auto-disable After Repeated Failures'))
                        ->helperText($this->localizedSettingDescription('scheduled_tasks_auto_disable_on_failures', 'Disable scheduler processing automatically after the configured number of consecutive failures.'))
                        ->required(),
                    TextInput::make('scheduled_tasks_failure_threshold')
                        ->label($this->localizedSettingLabel('scheduled_tasks_failure_threshold', 'Consecutive Failure Threshold'))
                        ->helperText($this->localizedSettingDescription('scheduled_tasks_failure_threshold', 'Number of consecutive failures that triggers automatic scheduler disablement.'))
                        ->numeric()
                        ->integer()
                        ->required(),
                ])
                ->columns(1),
        ];

        if (! empty($additionalSchedulerSettings)) {
            $schema[] = Section::make('additional_scheduler_settings')
                ->heading(__('settings.settings_page.sections.additional_scheduler_settings.heading'))
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
                    ->label($this->localizedSettingLabel('app_display_timezone', 'Display Time Zone'))
                    ->helperText($this->localizedSettingDescription('app_display_timezone', 'Time zone used only for dates and times shown in the administration interface. Stored timestamps, background processing, and scheduler timing are not changed.'));
            } elseif ($name === 'pagination_per_page_base') {
                $explicit['pagination_per_page_base'] = $component
                    ->label($this->localizedSettingLabel('pagination_per_page_base', 'Base Rows per Page'))
                    ->helperText($this->localizedSettingDescription('pagination_per_page_base', 'Base number of rows used by paginated tables. Available page-size choices are generated as half of this value rounded up to the nearest multiple of 5, the base value, double the value, and triple the value. For example, 100 produces 50, 100, 200, and 300.'));
            } elseif ($name === 'ui_locale') {
                $explicit['ui_locale'] = $component;
            } else {
                $unmatched[] = $component;
            }
        }

        $schema = [];

        if (isset($explicit['app_display_timezone']) || isset($explicit['pagination_per_page_base']) || isset($explicit['ui_locale'])) {
            $sectionSchema = [];
            if (isset($explicit['app_display_timezone'])) {
                $sectionSchema[] = $explicit['app_display_timezone'];
            }
            if (isset($explicit['pagination_per_page_base'])) {
                $sectionSchema[] = $explicit['pagination_per_page_base'];
            }
            if (isset($explicit['ui_locale'])) {
                $sectionSchema[] = $explicit['ui_locale'];
            }

            $schema[] = Section::make('application_display')
                ->heading(__('settings.settings_page.sections.application_display.heading'))
                ->description(__('settings.settings_page.sections.application_display.description'))
                ->schema($sectionSchema)
                ->columns([
                    'default' => 1,
                    'sm' => 2,
                ]);
        }

        if (! empty($unmatched)) {
            $schema[] = Section::make('additional_application_settings')
                ->heading(__('settings.settings_page.sections.additional_application_settings.heading'))
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
                ->label($this->localizedSettingLabel('cleanup_enabled', 'Automatic Local Data Cleanup'))
                ->helperText($this->localizedSettingDescription('cleanup_enabled', 'Enable scheduled cleanup of old local integration records. Disabling this option preserves all retention settings but prevents automatic deletion. This does not delete active Zabbix problems or Znuny tickets.'));
        }

        if (isset($explicit['cleanup_batch_size'])) {
            $explicit['cleanup_batch_size']
                ->label($this->localizedSettingLabel('cleanup_batch_size', 'Records per Cleanup Batch'))
                ->helperText($this->localizedSettingDescription('cleanup_batch_size', 'Maximum number of records removed from each cleanup category during one cleanup pass. Lower values reduce database load; higher values clear accumulated old data faster.'));
        }

        if (isset($explicit['retention_resolved_days'])) {
            $explicit['retention_resolved_days']
                ->label($this->localizedSettingLabel('retention_resolved_days', 'Resolved Problem History (days)'))
                ->helperText($this->localizedSettingDescription('retention_resolved_days', 'Number of days to keep local history for Zabbix problems after they become resolved. This does not delete problems, events, or history from Zabbix.'));
        }

        if (isset($explicit['retention_closed_tickets_days'])) {
            $explicit['retention_closed_tickets_days']
                ->label($this->localizedSettingLabel('retention_closed_tickets_days', 'Closed Ticket Link History (days)'))
                ->helperText($this->localizedSettingDescription('retention_closed_tickets_days', 'Number of days to keep local integration records and links for closed tickets. This does not delete tickets, articles, or history from Znuny.'));
        }

        if (isset($explicit['retention_action_logs_days'])) {
            $explicit['retention_action_logs_days']
                ->label($this->localizedSettingLabel('retention_action_logs_days', 'Action Log Retention (days)'))
                ->helperText($this->localizedSettingDescription('retention_action_logs_days', 'Number of days to keep local application action-log records used for operational history, auditing, and troubleshooting.'));
        }

        if (isset($explicit['scheduled_task_logs_retention_days'])) {
            $explicit['scheduled_task_logs_retention_days']
                ->label($this->localizedSettingLabel('scheduled_task_logs_retention_days', 'Scheduled Task Run Log Retention (days)'))
                ->helperText($this->localizedSettingDescription('scheduled_task_logs_retention_days', 'Number of days to keep execution logs for scheduled Znuny task runs. Scheduled task definitions and pending scheduled work are not deleted by this retention setting.'));
        }

        if (isset($explicit['retention_failed_jobs_days'])) {
            $explicit['retention_failed_jobs_days']
                ->label($this->localizedSettingLabel('retention_failed_jobs_days', 'Failed Job Retention (days)'))
                ->helperText($this->localizedSettingDescription('retention_failed_jobs_days', 'Number of days to keep failed background-job records for diagnostics and troubleshooting.'));
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
            $schema[] = Section::make('cleanup_control')
                ->heading(__('settings.settings_page.sections.cleanup_control.heading'))
                ->description(__('settings.settings_page.sections.cleanup_control.description'))
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
            $schema[] = Section::make('integration_history')
                ->heading(__('settings.settings_page.sections.integration_history.heading'))
                ->description(__('settings.settings_page.sections.integration_history.description'))
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
            $schema[] = Section::make('logs_and_processing_records')
                ->heading(__('settings.settings_page.sections.logs_and_processing_records.heading'))
                ->description(__('settings.settings_page.sections.logs_and_processing_records.description'))
                ->schema($section3)
                ->columns([
                    'default' => 1,
                    'sm' => 3,
                ]);
        }

        if (! empty($unmatched)) {
            $schema[] = Section::make('additional_retention_settings')
                ->heading(__('settings.settings_page.sections.additional_retention_settings.heading'))
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
                'znuny_prewarm_queues_interval_minutes',
                'znuny_prewarm_agents_interval_minutes',
                'znuny_prewarm_lookups_interval_minutes',
                'znuny_prewarm_customer_users_interval_minutes',
                'znuny_ticket_article_cache_ttl_minutes',
                'znuny_ticket_snapshot_cache_ttl_minutes',
            ])) {
                $explicit[$name] = $component;
            } else {
                $unmatched[] = $component;
            }
        }

        if (isset($explicit['znuny_prewarm_queues_interval_minutes'])) {
            $explicit['znuny_prewarm_queues_interval_minutes']
                ->label($this->localizedSettingLabel('znuny_prewarm_queues_interval_minutes', 'Значення інтервалу оновлення черг Znuny (у хвилинах)'))
                ->helperText($this->localizedSettingDescription('znuny_prewarm_queues_interval_minutes', 'Інтервал фонового оновлення кешу черг.'))
                ->numeric()
                ->minValue(3);
        }

        if (isset($explicit['znuny_prewarm_agents_interval_minutes'])) {
            $explicit['znuny_prewarm_agents_interval_minutes']
                ->label($this->localizedSettingLabel('znuny_prewarm_agents_interval_minutes', 'Значення інтервалу оновлення агентів Znuny (у хвилинах)'))
                ->helperText($this->localizedSettingDescription('znuny_prewarm_agents_interval_minutes', 'Інтервал фонового оновлення кешу агентів.'))
                ->numeric()
                ->minValue(3);
        }

        if (isset($explicit['znuny_prewarm_lookups_interval_minutes'])) {
            $explicit['znuny_prewarm_lookups_interval_minutes']
                ->label($this->localizedSettingLabel('znuny_prewarm_lookups_interval_minutes', 'Значення інтервалу оновлення довідників Znuny (у хвилинах)'))
                ->helperText($this->localizedSettingDescription('znuny_prewarm_lookups_interval_minutes', 'Інтервал фонового оновлення станів, пріоритетів та типів.'))
                ->numeric()
                ->minValue(3);
        }

        if (isset($explicit['znuny_prewarm_customer_users_interval_minutes'])) {
            $explicit['znuny_prewarm_customer_users_interval_minutes']
                ->label($this->localizedSettingLabel('znuny_prewarm_customer_users_interval_minutes', 'Значення інтервалу оновлення клієнтів Znuny (у хвилинах)'))
                ->helperText($this->localizedSettingDescription('znuny_prewarm_customer_users_interval_minutes', 'Інтервал фонового оновлення клієнтів (CustomerUsers).'))
                ->numeric()
                ->minValue(3);
        }

        if (isset($explicit['znuny_ticket_article_cache_ttl_minutes'])) {
            $explicit['znuny_ticket_article_cache_ttl_minutes']
                ->label($this->localizedSettingLabel('znuny_ticket_article_cache_ttl_minutes', 'Ticket Article Cache Lifetime (minutes)'))
                ->helperText($this->localizedSettingDescription('znuny_ticket_article_cache_ttl_minutes', 'How long Znuny ticket articles fetched for linked tickets may be cached. Set to 0 to bypass persistent ticket article caching.'));
        }

        if (isset($explicit['znuny_ticket_snapshot_cache_ttl_minutes'])) {
            $explicit['znuny_ticket_snapshot_cache_ttl_minutes']
                ->label($this->localizedSettingLabel('znuny_ticket_snapshot_cache_ttl_minutes', 'Linked Ticket Snapshot Cache Lifetime (minutes)'))
                ->helperText($this->localizedSettingDescription('znuny_ticket_snapshot_cache_ttl_minutes', 'Configured lifetime for cached linked-ticket snapshot data. A snapshot may include locally stored Znuny ticket details such as state, owner, queue, priority, and synchronization metadata. This setting does not control Ticket Workspace caching and does not delete local ticket links or data in Znuny.'));
        }

        $schema = [];

        if (isset($explicit['znuny_prewarm_queues_interval_minutes']) || isset($explicit['znuny_prewarm_agents_interval_minutes']) || isset($explicit['znuny_prewarm_lookups_interval_minutes']) || isset($explicit['znuny_prewarm_customer_users_interval_minutes'])) {
            $section1 = [];
            if (isset($explicit['znuny_prewarm_queues_interval_minutes'])) {
                $section1[] = $explicit['znuny_prewarm_queues_interval_minutes'];
            }
            if (isset($explicit['znuny_prewarm_agents_interval_minutes'])) {
                $section1[] = $explicit['znuny_prewarm_agents_interval_minutes'];
            }
            if (isset($explicit['znuny_prewarm_lookups_interval_minutes'])) {
                $section1[] = $explicit['znuny_prewarm_lookups_interval_minutes'];
            }
            if (isset($explicit['znuny_prewarm_customer_users_interval_minutes'])) {
                $section1[] = $explicit['znuny_prewarm_customer_users_interval_minutes'];
            }
            $schema[] = Section::make('znuny_reference_data')
                ->heading(__('settings.settings_page.sections.znuny_reference_data.heading'))
                ->description(__('settings.settings_page.sections.znuny_reference_data.description'))
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

            $schema[] = Section::make('znuny_linked_ticket_data')
                ->heading(__('settings.settings_page.sections.znuny_linked_ticket_data.heading'))
                ->description(__('settings.settings_page.sections.znuny_linked_ticket_data.description'))
                ->schema($section2)
                ->columns(1);
        }

        if (! empty($unmatched)) {
            $schema[] = Section::make('additional_cache_settings')
                ->heading(__('settings.settings_page.sections.additional_cache_settings.heading'))
                ->description(__('settings.settings_page.sections.additional_cache_settings.description'))
                ->schema($unmatched)
                ->columns(1);
        }

        $schema[] = Section::make('runtime_cache_maintenance')
            ->heading(__('settings.settings_page.sections.runtime_cache_maintenance.heading'))
            ->description(__('settings.settings_page.sections.runtime_cache_maintenance.description'))
            ->schema([
                Actions::make([
                    Action::make('clearTicketArticleCache')
                        ->label(__('settings.settings_page.actions.clear_ticket_article_cache.label'))
                        ->color('warning')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->modalHeading(__('settings.settings_page.actions.clear_ticket_article_cache.modal_heading'))
                        ->modalDescription(__('settings.settings_page.actions.clear_ticket_article_cache.modal_description'))
                        ->modalSubmitActionLabel(__('settings.settings_page.actions.clear_ticket_article_cache.modal_submit_action_label'))
                        ->action('clearTicketArticleCacheAction')
                        ->visible(fn () => auth()->user()?->role === 'admin'),
                ]),
            ])
            ->columns(1);

        return $schema;
    }
}
