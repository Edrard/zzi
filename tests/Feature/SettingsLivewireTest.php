<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Services\Zabbix\ZabbixProblemCache;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyQueueHostMappingService;
use App\Services\Znuny\ZnunyQueueService;
use App\Services\Znuny\ZnunyTicketDefaultRuleService;
use App\Support\Settings\DefaultSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsLivewireTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSIENT_FORM_FIELD_NAMES = [
        'mail_smtp_password_clear',
    ];

    private function isActionComponent(object $component): bool
    {
        return $component instanceof Actions
            || $component instanceof Action;
    }

    public function test_global_save_normalizes_znuny_queue_host_mappings()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Create the settings needed to pass validation
        Setting::updateOrCreate(['key' => 'zabbix_api_url'], ['type' => 'string', 'value' => 'http://example.com']);
        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['type' => 'string', 'value' => 'http://example.com']);
        Setting::updateOrCreate(['key' => 'znuny_web_url'], ['type' => 'string', 'value' => 'http://example.com']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['type' => 'string', 'value' => 'user']);
        Setting::updateOrCreate(['key' => 'pagination_per_page_base'], ['type' => 'integer', 'value' => '100']);
        Setting::updateOrCreate(['key' => 'znuny_queue_host_mappings'], ['type' => 'json', 'value' => json_encode([])]);

        $mockClient = \Mockery::mock(ZnunyClient::class);
        $mockClient->shouldReceive('getQueues')->andReturn([
            ['id' => 1, 'name' => 'Queue1', 'full_name' => 'Queue1'],
            ['id' => 2, 'name' => 'Queue2', 'full_name' => 'Queue2'],
        ]);
        $this->app->instance(ZnunyClient::class, $mockClient);

        $rawMappings = [
            'item-1' => ['host_prefix' => '  Prefix1  ', 'queue_name' => 'Queue1', 'note' => '  Note  '],
            'item-2' => ['host_prefix' => '', 'queue_name' => 'Queue2', 'note' => ''], // empty prefix dropped
            'item-3' => ['host_prefix' => 'Prefix3', 'queue_name' => '', 'note' => ''], // empty queue dropped
        ];

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm([
                'zabbix_api_url' => 'http://example.com',
                'znuny_username' => 'user',
                'znuny_queue_host_mappings' => $rawMappings,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $setting = Setting::where('key', 'znuny_queue_host_mappings')->first();
        $this->assertNotNull($setting);
        $val = json_decode($setting->value, true);

        $this->assertCount(1, $val);
        $this->assertEquals('Prefix1', $val[0]['host_prefix']);
        $this->assertEquals('Queue1', $val[0]['queue_name']);
        $this->assertEquals('Note', $val[0]['note']);
    }

    public function test_manual_ticket_auto_close_schedule_mode_is_in_automation_tab_and_no_other_tab()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);

        $form = $component->instance()->getForm('form');
        $schema = $form->getComponents();

        // Recursively search for components
        $foundMode = false;
        $foundEnabled = false;
        $foundOtherTab = false;
        $automationTabModeFound = false;

        $search = function ($components, $parentGroupName = null) use (&$search, &$foundMode, &$foundEnabled, &$foundOtherTab, &$automationTabModeFound) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                $label = method_exists($c, 'getLabel') ? $c->getLabel() : null;

                if ($type === 'Tab' && $label === 'Other') {
                    $foundOtherTab = true;
                }

                if ($type === 'Tab' && $label === 'Automation') {
                    $parentGroupName = 'Automation';
                }

                if ($name === 'manual_ticket_auto_close_enabled') {
                    $foundEnabled = true;
                }

                if ($name === 'manual_ticket_auto_close_schedule_mode') {
                    $foundMode = true;
                    if ($parentGroupName === 'Automation') {
                        $automationTabModeFound = true;
                    }
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $parentGroupName);
                }
            }
        };

        $search($schema);

        $this->assertTrue($foundMode, 'manual_ticket_auto_close_schedule_mode should be rendered');
        $this->assertFalse($foundEnabled, 'manual_ticket_auto_close_enabled should not be rendered');
        $this->assertFalse($foundOtherTab, 'Other tab should not be rendered when empty');
        $this->assertTrue($automationTabModeFound, 'manual_ticket_auto_close_schedule_mode should be in Automation tab');
    }

    public function test_app_display_timezone_is_in_general_tab()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);

        $form = $component->instance()->getForm('form');
        $schema = $form->getComponents();

        $foundTimezone = false;
        $generalTabTimezoneFound = false;
        $foundOtherTab = false;

        $search = function ($components, $parentGroupName = null) use (&$search, &$foundTimezone, &$generalTabTimezoneFound, &$foundOtherTab) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                $label = method_exists($c, 'getLabel') ? $c->getLabel() : null;

                if ($type === 'Tab' && $label === 'Other') {
                    $foundOtherTab = true;
                }

                if ($type === 'Tab' && $label === 'General') {
                    $parentGroupName = 'General';
                }

                if ($name === 'app_display_timezone') {
                    $foundTimezone = true;
                    if ($parentGroupName === 'General') {
                        $generalTabTimezoneFound = true;
                    }
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $parentGroupName);
                }
            }
        };

        $search($schema);

        $this->assertTrue($foundTimezone, 'app_display_timezone should be rendered');
        $this->assertTrue($generalTabTimezoneFound, 'app_display_timezone should be in General tab');
        $this->assertFalse($foundOtherTab, 'Other tab should not be rendered when empty');
    }

    public function test_ticket_workspace_tab_sections_and_fields()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Artisan::call('app:ensure-settings-defaults');

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $form = $component->instance()->getForm('form');
        $schema = $form->getComponents();

        $sections = [];

        $search = function ($components, $parentGroupName = null) use (&$search, &$sections) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                $label = method_exists($c, 'getHeading') ? $c->getHeading() : (method_exists($c, 'getLabel') ? $c->getLabel() : null);

                // Keep track of the current section
                if ($type === 'Section' && $label) {
                    $parentGroupName = $label;
                    if (! isset($sections[$parentGroupName])) {
                        $sections[$parentGroupName] = [];
                    }
                }

                if ($name && $parentGroupName && ! $this->isActionComponent($c)) {
                    $sections[$parentGroupName][] = $name;
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $parentGroupName);
                }
            }
        };

        $search($schema);

        // Assert Core Workspace settings
        $this->assertContains('znuny_ticket_workspace_enabled', $sections['Ticket Workspace'] ?? []);
        $this->assertContains('znuny_ticket_workspace_active_state_type_ids', $sections['Ticket Workspace'] ?? []);

        // Assert Active Ticket Cache settings
        $this->assertContains('znuny_ticket_cache_refresh_interval_minutes', $sections['Active Ticket Cache'] ?? []);
        $this->assertContains('znuny_ticket_cache_default_limit', $sections['Active Ticket Cache'] ?? []);
        $this->assertContains('znuny_ticket_cache_max_pages_per_run', $sections['Active Ticket Cache'] ?? []);
        $this->assertContains('znuny_ticket_cache_ttl_minutes', $sections['Active Ticket Cache'] ?? []);

        // Assert Legacy Closed Ticket Cache setting is completely removed
        $this->assertArrayNotHasKey('Legacy Closed Ticket Cache', $sections);
        $this->assertArrayNotHasKey('Advanced / Internal', $sections);

        // Assert Recent Closed Tickets settings
        $this->assertContains('znuny_closed_ticket_window_days', $sections['Recent Closed Tickets'] ?? []);
        $this->assertContains('znuny_closed_ticket_small_sync_interval_minutes', $sections['Recent Closed Tickets'] ?? []);
        $this->assertNotContains('znuny_closed_ticket_sync_audit_auto_enabled', $sections['Recent Closed Tickets'] ?? []);

        // Assert all form settings exist in DefaultSettings
        $allDefaults = collect(DefaultSettings::all())->pluck('key')->toArray();
        $renderedSettings = collect($sections)->flatten()->unique()->toArray();
        foreach ($renderedSettings as $renderedSetting) {
            // Some keys are dynamic or not settings, but all 'znuny_' or standard setting keys should exist
            if (
                in_array($renderedSetting, ['znuny_queue_host_mappings', 'host_prefix', 'queue_name', 'note'], true)
                || in_array($renderedSetting, self::TRANSIENT_FORM_FIELD_NAMES, true)
            ) {
                continue;
            } // Mappings are handled specially
            $this->assertContains($renderedSetting, $allDefaults, "Setting $renderedSetting rendered in form but not found in DefaultSettings");
        }
    }

    public function test_audit_log_tab_contains_audit_settings()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Artisan::call('app:ensure-settings-defaults');

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $form = $component->instance()->getForm('form');
        $schema = $form->getComponents();

        $found = false;

        $search = function ($components) use (&$search, &$found) {
            foreach ($components as $c) {
                if (method_exists($c, 'getName') && $c->getName() === 'znuny_closed_ticket_sync_audit_auto_enabled') {
                    $found = true;
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };

        $search($schema);

        $this->assertTrue($found, 'Audit Log setting should be rendered in the form.');
    }

    public function test_znuny_connection_test_button_is_rendered_in_credentials_and_endpoints_tabs()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);

        $form = $component->instance()->getForm('form');
        $schema = $form->getComponents();

        $foundCredentialsButton = false;
        $foundEndpointsButton = false;

        $search = function ($components) use (&$search, &$foundCredentialsButton, &$foundEndpointsButton) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $name = method_exists($c, 'getName') ? $c->getName() : null;

                if ($type === 'Action' && $name === 'testZnunyConnection_Credentials') {
                    $foundCredentialsButton = true;
                }

                if ($type === 'Action' && $name === 'testZnunyConnection_Endpoints') {
                    $foundEndpointsButton = true;
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };

        $search($schema);

        $this->assertTrue($foundCredentialsButton, 'Test Znuny API Connection button should be rendered in Credentials tab');
        $this->assertTrue($foundEndpointsButton, 'Test Znuny API Connection button should be rendered in Endpoints tab');
    }

    public function test_ticket_workspace_active_state_type_ids_is_rendered_as_multiple_select()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['type' => 'boolean', 'value' => 'true']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_active_state_type_ids'], ['type' => 'json', 'value' => '["new","open"]']);

        $component = Livewire::actingAs($admin)->test(Settings::class);

        $form = $component->instance()->getForm('form');
        $schema = $form->getComponents();

        $foundSelect = false;
        $isMultiple = false;
        $options = [];
        $foundObsoleteKeys = false;
        $ticketWorkspaceKeys = [];

        $search = function ($components, $parentGroupName = null) use (&$search, &$foundSelect, &$isMultiple, &$options, &$foundObsoleteKeys, &$ticketWorkspaceKeys) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $name = method_exists($c, 'getName') ? $c->getName() : null;

                if ($type === 'Tab' && method_exists($c, 'getLabel') && $c->getLabel() === 'Ticket Workspace') {
                    $parentGroupName = 'Ticket Workspace';
                }

                if (in_array($name, ['znuny_ticket_cache_ttl_seconds', 'znuny_ticket_cache_closed_ttl_seconds', 'znuny_ticket_cache_active_state_types'])) {
                    $foundObsoleteKeys = true;
                }

                if ($parentGroupName === 'Ticket Workspace' && $name) {
                    $ticketWorkspaceKeys[] = $name;
                }

                if ($name === 'znuny_ticket_workspace_active_state_type_ids') {
                    if ($type === 'Select') {
                        $foundSelect = true;
                        $isMultiple = $c->isMultiple();
                        $options = $c->getOptions();
                    }
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $parentGroupName);
                }
            }
        };

        $search($schema);

        $this->assertTrue($foundSelect, 'znuny_ticket_workspace_active_state_type_ids should be rendered as a Select component');
        $this->assertTrue($isMultiple, 'Select component should be multiple');
        $this->assertArrayHasKey('new', $options);
        $this->assertArrayHasKey('pending_reminder', $options);

        $this->assertFalse($foundObsoleteKeys, 'Obsolete keys should not be rendered');

        // Ensure znuny_ticket_workspace_enabled is first
        $this->assertEquals('znuny_ticket_workspace_enabled', $ticketWorkspaceKeys[0], 'znuny_ticket_workspace_enabled must be the first field in the Ticket Workspace tab');
    }

    public function test_ticket_workspace_active_state_type_ids_is_saved_as_json_array()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Set minimum required to pass form validation
        Setting::updateOrCreate(['key' => 'zabbix_api_url'], ['type' => 'string', 'value' => 'http://example.com']);
        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['type' => 'string', 'value' => 'http://example.com']);
        Setting::updateOrCreate(['key' => 'znuny_web_url'], ['type' => 'string', 'value' => 'http://example.com']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['type' => 'string', 'value' => 'user']);
        Setting::updateOrCreate(['key' => 'pagination_per_page_base'], ['type' => 'integer', 'value' => '100']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_active_state_type_ids'], ['type' => 'json', 'value' => '["new"]']);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm([
                'zabbix_api_url' => 'http://example.com',
                'znuny_username' => 'user',
                'znuny_ticket_workspace_active_state_type_ids' => ['open', 'pending_auto'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $setting = Setting::where('key', 'znuny_ticket_workspace_active_state_type_ids')->first();
        $this->assertEquals('["open","pending_auto"]', $setting->value);
    }

    public function test_audit_log_settings_have_default_false_from_migration()
    {
        $this->assertEquals('false', Setting::where('key', 'zabbix_problem_sync_audit_enabled')->value('value'));
        $this->assertEquals('false', Setting::where('key', 'znuny_ticket_workspace_sync_audit_enabled')->value('value'));
    }

    public function test_audit_log_tab_is_rendered_with_correct_settings()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Setting::updateOrCreate(['key' => 'znuny_detailed_sync_audit_enabled'], ['type' => 'boolean', 'value' => 'false']);
        Setting::updateOrCreate(['key' => 'zabbix_problem_sync_audit_enabled'], ['type' => 'boolean', 'value' => 'false']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_sync_audit_enabled'], ['type' => 'boolean', 'value' => 'false']);

        $component = Livewire::actingAs($admin)->test(Settings::class);

        $form = $component->instance()->getForm('form');
        $schema = $form->getComponents();

        $auditLogTabFound = false;
        $detailedSyncAuditFound = false;
        $problemSyncAuditFound = false;
        $workspaceSyncAuditFound = false;

        $detailedSyncInAuditLog = false;
        $problemSyncInAuditLog = false;
        $workspaceSyncInAuditLog = false;

        $detailedSyncInLinkedTickets = false;

        $search = function ($components, $parentGroupName = null) use (&$search, &$auditLogTabFound, &$detailedSyncAuditFound, &$problemSyncAuditFound, &$workspaceSyncAuditFound, &$detailedSyncInAuditLog, &$problemSyncInAuditLog, &$workspaceSyncInAuditLog, &$detailedSyncInLinkedTickets) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                $label = method_exists($c, 'getLabel') ? $c->getLabel() : null;

                if ($type === 'Tab') {
                    if ($label === 'Audit Log') {
                        $auditLogTabFound = true;
                        $parentGroupName = 'Audit Log';
                    } elseif ($label === 'Linked Tickets') {
                        $parentGroupName = 'Linked Tickets';
                    }
                }

                if ($name === 'znuny_detailed_sync_audit_enabled') {
                    $detailedSyncAuditFound = true;
                    if ($parentGroupName === 'Audit Log') {
                        $detailedSyncInAuditLog = true;
                    }
                    if ($parentGroupName === 'Linked Tickets') {
                        $detailedSyncInLinkedTickets = true;
                    }
                }

                if ($name === 'zabbix_problem_sync_audit_enabled') {
                    $problemSyncAuditFound = true;
                    if ($parentGroupName === 'Audit Log') {
                        $problemSyncInAuditLog = true;
                    }
                }

                if ($name === 'znuny_ticket_workspace_sync_audit_enabled') {
                    $workspaceSyncAuditFound = true;
                    if ($parentGroupName === 'Audit Log') {
                        $workspaceSyncInAuditLog = true;
                    }
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $parentGroupName);
                }
            }
        };

        $search($schema);

        $this->assertTrue($auditLogTabFound, 'Audit Log tab should be rendered');
        $this->assertTrue($detailedSyncAuditFound, 'znuny_detailed_sync_audit_enabled should be rendered');
        $this->assertTrue($problemSyncAuditFound, 'zabbix_problem_sync_audit_enabled should be rendered');
        $this->assertTrue($workspaceSyncAuditFound, 'znuny_ticket_workspace_sync_audit_enabled should be rendered');

        $this->assertTrue($detailedSyncInAuditLog, 'znuny_detailed_sync_audit_enabled should be in Audit Log tab');
        $this->assertTrue($problemSyncInAuditLog, 'zabbix_problem_sync_audit_enabled should be in Audit Log tab');
        $this->assertTrue($workspaceSyncInAuditLog, 'znuny_ticket_workspace_sync_audit_enabled should be in Audit Log tab');

        $this->assertFalse($detailedSyncInLinkedTickets, 'znuny_detailed_sync_audit_enabled should no longer be in Linked Tickets tab');
    }

    public function test_all_settings_in_form_are_in_default_registry()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // First, run the defaults command to seed everything
        Artisan::call('app:ensure-settings-defaults');

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $form = $component->instance()->getForm('form');
        $schema = $form->getComponents();

        $formKeys = [];

        $search = function ($components) use (&$search, &$formKeys) {
            foreach ($components as $c) {
                if (method_exists($c, 'getName')) {
                    $name = $c->getName();
                    if ($name && ! in_array($name, ['SettingsTabs', 'data', 'saveBottom', 'save'])) {
                        // Skip actions or placeholders that are not actual setting keys
                        if (
                            ! $this->isActionComponent($c)
                            && ! str_starts_with($name, 'tester_help_')
                            && $name !== 'zabbix_tester_help'
                            && $name !== 'host_prefix'
                            && $name !== 'queue_name'
                            && $name !== 'note'
                            && $name !== 'auto_tickets_placeholder'
                            && $name !== 'problem_highlighting_preview'
                            && $name !== 'regex'
                            && ! in_array($name, self::TRANSIENT_FORM_FIELD_NAMES, true)
                        ) {
                            $formKeys[] = $name;
                        }
                    }
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };

        $search($schema);

        $defaultSettings = collect(DefaultSettings::all())->pluck('key')->toArray();

        foreach ($formKeys as $key) {
            $this->assertContains($key, $defaultSettings, "Setting key '{$key}' rendered in UI is missing from DefaultSettings registry.");
        }

        // Also ensure explicitly ignored keys in the UI are in the defaults registry
        $ignoredKeys = ['manual_ticket_auto_close_enabled'];
        foreach ($ignoredKeys as $key) {
            $this->assertContains($key, $defaultSettings, "Ignored setting key '{$key}' is missing from DefaultSettings registry.");
        }
    }

    public function test_closed_ticket_operational_ui_is_not_in_settings()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)->test(Settings::class)
            ->assertSuccessful()
            ->assertDontSee('Sync Recent Closed Tickets')
            ->assertDontSee('Recent Closed Ticket Cache Status')
            ->assertDontSee('closed_ticket_sync_status');
    }

    public function test_zabbix_attention_highlighting_ui_is_rendered_in_zabbix_tab()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // First, run the defaults command to seed everything
        Artisan::call('app:ensure-settings-defaults');

        $component = Livewire::actingAs($admin)->test(Settings::class);

        $form = $component->instance()->getForm('form');
        $schema = $form->getComponents();

        $zabbixTabFound = false;
        $problemHighlightingFound = false;
        $settingsFound = [];

        $search = function ($components, $parentGroupName = null) use (&$search, &$zabbixTabFound, &$problemHighlightingFound, &$settingsFound) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                $label = method_exists($c, 'getLabel') ? $c->getLabel() : null;

                if ($type === 'Tab') {
                    if ($label === 'Zabbix') {
                        $parentGroupName = 'Zabbix';
                        $zabbixTabFound = true;
                    } elseif ($label === 'Problem Highlighting') {
                        $problemHighlightingFound = true;
                    }
                }

                $keys = [
                    'zabbix_attention_highlighting_enabled',
                    'problem_highlighting_preview',
                    'zabbix_attention_highlight_text_color',
                    'zabbix_attention_highlight_text_custom_hex',
                    'zabbix_attention_highlight_underline_style',
                    'zabbix_attention_highlight_underline_thickness',
                    'zabbix_attention_highlight_underline_color',
                    'zabbix_attention_highlight_underline_custom_hex',
                ];

                if (in_array($name, $keys)) {
                    $settingsFound[] = $name;
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $parentGroupName);
                }
            }
        };

        $search($schema);

        $this->assertTrue($zabbixTabFound, 'Zabbix tab should be rendered');
        $this->assertTrue($problemHighlightingFound, 'Problem Highlighting tab should be rendered');

        $expectedOrder = [
            'zabbix_attention_highlighting_enabled',
            'problem_highlighting_preview',
            'zabbix_attention_highlight_text_color',
            'zabbix_attention_highlight_text_custom_hex',
            'zabbix_attention_highlight_underline_style',
            'zabbix_attention_highlight_underline_thickness',
            'zabbix_attention_highlight_underline_color',
            'zabbix_attention_highlight_underline_custom_hex',
        ];

        // The exact array should match the expected order
        $this->assertEquals($expectedOrder, $settingsFound, 'The Problem Highlighting fields are missing or incorrectly ordered.');

        // Verify live preview renders correctly
        $component->assertSee(__('settings.settings_page.fields.problem_highlighting_preview.sample'), false);
    }

    public function test_missing_settings_are_created_automatically_on_mount_without_overwriting_existing_ones()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Set one setting manually to ensure it's not overwritten
        Setting::updateOrCreate(
            ['key' => 'zabbix_attention_highlighting_enabled'],
            ['type' => 'boolean', 'value' => 'false', 'description' => 'User configured false']
        );

        // Delete another setting so it's missing
        Setting::where('key', 'zabbix_attention_highlight_text_color')->delete();

        // Mount the page
        $component = Livewire::actingAs($admin)->test(Settings::class);
        $component->assertSuccessful();

        // Check the missing setting was created with default value
        $this->assertEquals('aquamarine', Setting::where('key', 'zabbix_attention_highlight_text_color')->value('value'));

        // Check the existing setting was NOT overwritten
        $this->assertEquals('false', Setting::where('key', 'zabbix_attention_highlighting_enabled')->value('value'));
    }

    public function test_problem_highlighting_custom_hex_hydration_and_validation()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Artisan::call('app:ensure-settings-defaults');

        Setting::updateOrCreate(
            ['key' => 'zabbix_attention_highlight_text_custom_hex'],
            ['type' => 'string', 'value' => '#123456']
        );

        $validRequiredFields = [
            'zabbix_api_url' => 'http://localhost/zabbix',
            'znuny_api_url' => 'http://localhost/znuny',
            'znuny_web_url' => 'http://localhost/znuny',
            'znuny_username' => 'testuser',
            'znuny_password' => 'testpass',
        ];

        foreach ($validRequiredFields as $key => $val) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['type' => 'string', 'value' => $val]
            );
        }

        $component = Livewire::actingAs($admin)->test(Settings::class);

        // 1. Existing stored values with `#` hydrate correctly (displayed without #)
        $component->assertFormSet(['zabbix_attention_highlight_text_custom_hex' => '123456']);

        // 2. Saving `7fffd4` stores `#7FFFD4`
        $component->fillForm([
            'zabbix_attention_highlight_text_color' => 'custom_hex',
            'zabbix_attention_highlight_text_custom_hex' => '7fffd4',
        ])->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals('#7FFFD4', Setting::where('key', 'zabbix_attention_highlight_text_custom_hex')->value('value'));

        // 3. Saving `7FFFD4` stores `#7FFFD4`
        $component->fillForm([
            'zabbix_attention_highlight_text_color' => 'custom_hex',
            'zabbix_attention_highlight_text_custom_hex' => '7FFFD4',
        ])->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals('#7FFFD4', Setting::where('key', 'zabbix_attention_highlight_text_custom_hex')->value('value'));

        // 4. Invalid values are rejected
        $component->fillForm([
            'zabbix_attention_highlight_text_color' => 'custom_hex',
            'zabbix_attention_highlight_text_custom_hex' => '', // empty
        ])->call('save')->assertHasFormErrors(['zabbix_attention_highlight_text_custom_hex' => 'required']);

        $component->fillForm([
            'zabbix_attention_highlight_text_color' => 'custom_hex',
            'zabbix_attention_highlight_text_custom_hex' => 'GGGGGG',
        ])->call('save')->assertHasFormErrors(['zabbix_attention_highlight_text_custom_hex' => 'regex']);

        $component->fillForm([
            'zabbix_attention_highlight_text_color' => 'custom_hex',
            'zabbix_attention_highlight_text_custom_hex' => '12345',
        ])->call('save')->assertHasFormErrors(['zabbix_attention_highlight_text_custom_hex' => 'regex']);

        $component->fillForm([
            'zabbix_attention_highlight_text_color' => 'custom_hex',
            'zabbix_attention_highlight_text_custom_hex' => '1234567',
        ])->call('save')->assertHasFormErrors(['zabbix_attention_highlight_text_custom_hex' => 'regex']);

        $component->fillForm([
            'zabbix_attention_highlight_text_color' => 'custom_hex',
            'zabbix_attention_highlight_text_custom_hex' => '#12345',
        ])->call('save')->assertHasFormErrors(['zabbix_attention_highlight_text_custom_hex' => 'regex']);

        $component->fillForm([
            'zabbix_attention_highlight_text_color' => 'custom_hex',
            'zabbix_attention_highlight_text_custom_hex' => '#1234567',
        ])->call('save')->assertHasFormErrors(['zabbix_attention_highlight_text_custom_hex' => 'regex']);
    }

    public function test_excludes_tab_contains_agent_and_queue_excludes()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Artisan::call('app:ensure-settings-defaults');

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $form = $component->instance()->getForm('form');
        $schema = $form->getComponents();

        $excludesTabFound = false;
        $agentExcludeFound = false;
        $queueExcludeFound = false;

        $agentExcludeInExcludes = false;
        $queueExcludeInExcludes = false;

        $search = function ($components, $parentGroupName = null) use (&$search, &$excludesTabFound, &$agentExcludeFound, &$queueExcludeFound, &$agentExcludeInExcludes, &$queueExcludeInExcludes) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                $label = method_exists($c, 'getLabel') ? $c->getLabel() : null;

                if ($type === 'Tab' && $label === 'Excludes') {
                    $excludesTabFound = true;
                    $parentGroupName = 'Excludes';
                }

                if ($name === 'znuny_agent_exclude_logins') {
                    $agentExcludeFound = true;
                    if ($parentGroupName === 'Excludes') {
                        $agentExcludeInExcludes = true;
                    }
                }

                if ($name === 'znuny_global_queue_exclusion_regexes') {
                    $queueExcludeFound = true;
                    if ($parentGroupName === 'Excludes') {
                        $queueExcludeInExcludes = true;
                    }
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $parentGroupName);
                }
            }
        };

        $search($schema);

        $this->assertTrue($excludesTabFound, 'Excludes tab should be rendered');
        $this->assertTrue($agentExcludeFound, 'znuny_agent_exclude_logins should be rendered');
        $this->assertTrue($queueExcludeFound, 'znuny_global_queue_exclusion_regexes should be rendered');

        $this->assertTrue($agentExcludeInExcludes, 'znuny_agent_exclude_logins should be in Excludes tab');
        $this->assertTrue($queueExcludeInExcludes, 'znuny_global_queue_exclusion_regexes should be in Excludes tab');
    }

    public function test_settings_ui_hydrates_queue_regex_object_rows_and_does_not_render_object_object()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Artisan::call('app:ensure-settings-defaults');

        Setting::updateOrCreate(
            ['key' => 'znuny_global_queue_exclusion_regexes'],
            ['type' => 'json', 'value' => json_encode([['regex' => '^Postmaster::']])]
        );

        $component = Livewire::actingAs($admin)->test(Settings::class);

        $state = $component->instance()->form->getRawState();
        $regexes = $state['znuny_global_queue_exclusion_regexes'] ?? [];

        $this->assertCount(1, $regexes);
        $firstKey = array_key_first($regexes);
        $this->assertEquals('^Postmaster::', $regexes[$firstKey]['regex']);

        $component->assertSee('^Postmaster::');
        $component->assertDontSee('[object Object]');
    }

    public function test_settings_ui_hydrates_queue_regex_string_list_rows()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Artisan::call('app:ensure-settings-defaults');

        Setting::updateOrCreate(
            ['key' => 'znuny_global_queue_exclusion_regexes'],
            ['type' => 'json', 'value' => json_encode(['^Postmaster::', '^Test'])]
        );

        $component = Livewire::actingAs($admin)->test(Settings::class);

        $state = $component->instance()->form->getRawState();
        $regexes = $state['znuny_global_queue_exclusion_regexes'] ?? [];

        $this->assertCount(2, $regexes);
        $keys = array_keys($regexes);
        $this->assertEquals('^Postmaster::', $regexes[$keys[0]]['regex']);
        $this->assertEquals('^Test', $regexes[$keys[1]]['regex']);
    }

    public function test_settings_ui_dehydrates_queue_regex_to_object_list_and_ignores_blank_rows()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Set minimum required to pass form validation
        Setting::updateOrCreate(['key' => 'zabbix_api_url'], ['type' => 'string', 'value' => 'http://example.com']);
        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['type' => 'string', 'value' => 'http://example.com']);
        Setting::updateOrCreate(['key' => 'znuny_web_url'], ['type' => 'string', 'value' => 'http://example.com']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['type' => 'string', 'value' => 'user']);
        Setting::updateOrCreate(['key' => 'pagination_per_page_base'], ['type' => 'integer', 'value' => '100']);
        Setting::updateOrCreate(['key' => 'znuny_global_queue_exclusion_regexes'], ['type' => 'json', 'value' => '[]']);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm([
                'zabbix_api_url' => 'http://example.com',
                'znuny_username' => 'user',
                'znuny_global_queue_exclusion_regexes' => [
                    'row1' => ['regex' => '^Postmaster::'],
                    'row2' => ['regex' => '  '], // blank row should be ignored
                    'row3' => ['regex' => '^Test'],
                    'row4' => ['regex' => '^Postmaster::'], // duplicate should be removed
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $setting = Setting::where('key', 'znuny_global_queue_exclusion_regexes')->first();
        $this->assertEquals('[{"regex":"^Postmaster::"},{"regex":"^Test"}]', $setting->value);
    }

    public function test_mail_smtp_password_clear_is_a_transient_form_only_control()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $form = $component->instance()->getForm('form');
        $schema = $form->getComponents();

        $formKeys = [];

        $search = function ($components) use (&$search, &$formKeys) {
            foreach ($components as $c) {
                if (method_exists($c, 'getName')) {
                    $name = $c->getName();
                    if ($name) {
                        $formKeys[] = $name;
                    }
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };

        $search($schema);

        $this->assertContains('mail_smtp_password_clear', $formKeys, 'The actual Settings form must contain mail_smtp_password_clear');

        $allDefaults = collect(DefaultSettings::all())->pluck('key')->toArray();
        $this->assertNotContains('mail_smtp_password_clear', $allDefaults, 'DefaultSettings::all() must not contain mail_smtp_password_clear');
    }

    public function test_named_settings_actions_are_not_treated_as_persistent_settings()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $form = $component->instance()->getForm('form');
        $schema = $form->getComponents();

        $actionNames = [];

        $search = function ($components) use (&$search, &$actionNames) {
            foreach ($components as $c) {
                if ($this->isActionComponent($c)) {
                    $name = method_exists($c, 'getName') ? $c->getName() : null;
                    if ($name) {
                        $actionNames[] = $name;
                    }
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };

        $search($schema);

        $this->assertContains('testMailConnection', $actionNames);
        $this->assertContains('clearSettingsCache', $actionNames);
        $this->assertContains('clearZnunyAgentCache', $actionNames);

        $allDefaults = collect(DefaultSettings::all())->pluck('key')->toArray();
        $this->assertNotContains('testMailConnection', $allDefaults);
        $this->assertNotContains('clearSettingsCache', $allDefaults);
        $this->assertNotContains('clearZnunyAgentCache', $allDefaults);
    }

    public function test_all_default_settings_have_complete_metadata_translations_for_supported_locales()
    {
        $defaultKeys = collect(DefaultSettings::all())->pluck('key')->toArray();
        $enTranslations = require base_path('lang/en/settings.php');
        $ukTranslations = require base_path('lang/uk/settings.php');

        $enMetadataKeys = array_keys($enTranslations['metadata'] ?? []);
        $ukMetadataKeys = array_keys($ukTranslations['metadata'] ?? []);

        $missingEn = array_diff($defaultKeys, $enMetadataKeys);
        $extraEn = array_diff($enMetadataKeys, $defaultKeys);
        $this->assertEmpty($missingEn, 'Missing EN keys: '.implode(', ', $missingEn));
        $this->assertEmpty($extraEn, 'Extra EN keys: '.implode(', ', $extraEn));

        $missingUk = array_diff($defaultKeys, $ukMetadataKeys);
        $extraUk = array_diff($ukMetadataKeys, $defaultKeys);
        $this->assertEmpty($missingUk, 'Missing UK keys: '.implode(', ', $missingUk));
        $this->assertEmpty($extraUk, 'Extra UK keys: '.implode(', ', $extraUk));

        $this->assertEqualsCanonicalizing($enMetadataKeys, $ukMetadataKeys);

        $this->assertNotContains('mail_smtp_password_clear', $enMetadataKeys);
        $this->assertNotContains('mail_smtp_password_clear', $ukMetadataKeys);

        foreach ($defaultKeys as $key) {
            $this->assertNotEmpty($enTranslations['metadata'][$key]['label']);
            $this->assertNotEmpty($ukTranslations['metadata'][$key]['label']);

            $defaultSetting = collect(DefaultSettings::all())->firstWhere('key', $key);
            if (! empty($defaultSetting['description'])) {
                $this->assertNotEmpty($enTranslations['metadata'][$key]['description'] ?? null);
                $this->assertNotEmpty($ukTranslations['metadata'][$key]['description'] ?? null);
            }
        }
    }

    public function test_settings_metadata_is_localized_without_altering_default_settings_or_values()
    {
        app()->setLocale('uk');
        $admin = User::factory()->create(['role' => 'admin']);
        $component = Livewire::actingAs($admin)->test(Settings::class);
        $form = $component->instance()->getForm('form');
        $schema = $form->getComponents();

        $foundFields = [];
        $search = function ($components) use (&$search, &$foundFields) {
            foreach ($components as $c) {
                if (method_exists($c, 'getName') && $c->getName()) {
                    $foundFields[$c->getName()] = $c;
                }
                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };
        $search($schema);

        $ukTranslations = require base_path('lang/uk/settings.php');

        // General
        $this->assertEquals($ukTranslations['metadata']['pagination_per_page_base']['label'], $foundFields['pagination_per_page_base']->getLabel());
        // Scheduler
        $this->assertEquals($ukTranslations['metadata']['scheduled_tasks_enabled']['label'], $foundFields['scheduled_tasks_enabled']->getLabel());
        // Mail
        $this->assertEquals($ukTranslations['metadata']['mail_notifications_enabled']['label'], $foundFields['mail_notifications_enabled']->getLabel());
        // Zabbix
        $this->assertEquals($ukTranslations['metadata']['zabbix_api_url']['label'], $foundFields['zabbix_api_url']->getLabel());
        // Znuny
        $this->assertEquals($ukTranslations['metadata']['znuny_api_url']['label'], $foundFields['znuny_api_url']->getLabel());
        // workflow
        $this->assertEquals($ukTranslations['metadata']['manual_ticket_auto_close_schedule_mode']['label'], $foundFields['manual_ticket_auto_close_schedule_mode']->getLabel());

        $this->assertEquals('Очистити збережений пароль SMTP', $foundFields['mail_smtp_password_clear']->getLabel());
        $this->assertEquals('Залиште порожнім, щоб зберегти поточний пароль', $foundFields['mail_smtp_password']->getPlaceholder());

        $expectedHelperText = $ukTranslations['metadata']['manual_ticket_auto_close_schedule_mode']['description'] ?? '';
        $component->assertSee($expectedHelperText, false);

        $defaultSetting = collect(DefaultSettings::all())->firstWhere('key', 'manual_ticket_auto_close_schedule_mode');
        $this->assertSame(
            'Scheduler mode for manual ticket auto-closing (disabled, dry_run, execute).',
            $defaultSetting['description'],
        );
        $this->assertNotSame($expectedHelperText, $defaultSetting['description']);

        $ticketTextSetting = $foundFields['znuny_manual_ticket_footer'] ?? null;
        $this->assertNotNull($ticketTextSetting);
    }

    public function test_localized_settings_option_labels_preserve_raw_values()
    {
        app()->setLocale('uk');
        $admin = User::factory()->create(['role' => 'admin']);
        $component = Livewire::actingAs($admin)->test(Settings::class);
        $form = $component->instance()->getForm('form');
        $schema = $form->getComponents();

        $foundFields = [];
        $search = function ($components) use (&$search, &$foundFields) {
            foreach ($components as $c) {
                if (method_exists($c, 'getName') && $c->getName()) {
                    $foundFields[$c->getName()] = $c;
                }
                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };
        $search($schema);

        $ukTranslations = require base_path('lang/uk/settings.php');

        $mailTransportOptions = $foundFields['mail_transport']->getOptions();
        $this->assertArrayHasKey('sendmail', $mailTransportOptions);
        $this->assertArrayHasKey('smtp', $mailTransportOptions);
        $this->assertEquals($ukTranslations['metadata']['mail_transport']['options']['sendmail'], $mailTransportOptions['sendmail']);
        $this->assertEquals($ukTranslations['metadata']['mail_transport']['options']['smtp'], $mailTransportOptions['smtp']);

        $scheduleModeOptions = $foundFields['manual_ticket_auto_close_schedule_mode']->getOptions();
        $this->assertArrayHasKey('disabled', $scheduleModeOptions);
        $this->assertArrayHasKey('dry_run', $scheduleModeOptions);
        $this->assertArrayHasKey('execute', $scheduleModeOptions);
        $this->assertEquals($ukTranslations['metadata']['manual_ticket_auto_close_schedule_mode']['options']['disabled'], $scheduleModeOptions['disabled']);
        $this->assertEquals($ukTranslations['metadata']['manual_ticket_auto_close_schedule_mode']['options']['dry_run'], $scheduleModeOptions['dry_run']);
        $this->assertEquals($ukTranslations['metadata']['manual_ticket_auto_close_schedule_mode']['options']['execute'], $scheduleModeOptions['execute']);
    }

    public function test_settings_ui_locale_does_not_rewrite_configurable_ticket_text_values()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // 1. use distinct known custom values for every confirmed ticket-text field
        Setting::updateOrCreate(['key' => 'znuny_manual_ticket_footer'], ['type' => 'string', 'value' => 'EN_FOOTER_TEST']);
        Setting::updateOrCreate(['key' => 'linked_ticket_manual_close_default_reason'], ['type' => 'string', 'value' => 'EN_CLOSE_TEST']);
        Setting::updateOrCreate(['key' => 'manual_ticket_reopen_note_template'], ['type' => 'string', 'value' => 'EN_REOPEN_TEST']);

        // 2. mount/hydrate the real Settings page under en
        app()->setLocale('en');
        $componentEn = Livewire::actingAs($admin)->test(Settings::class);

        // 3. verify values remain exact
        $componentEn->assertSet('data.znuny_manual_ticket_footer', 'EN_FOOTER_TEST');
        $componentEn->assertSet('data.linked_ticket_manual_close_default_reason', 'EN_CLOSE_TEST');
        $componentEn->assertSet('data.manual_ticket_reopen_note_template', 'EN_REOPEN_TEST');

        // 4. mount/hydrate under uk
        app()->setLocale('uk');
        $componentUk = Livewire::actingAs($admin)->test(Settings::class);

        // 5. verify the same values remain exact
        $componentUk->assertSet('data.znuny_manual_ticket_footer', 'EN_FOOTER_TEST');
        $componentUk->assertSet('data.linked_ticket_manual_close_default_reason', 'EN_CLOSE_TEST');
        $componentUk->assertSet('data.manual_ticket_reopen_note_template', 'EN_REOPEN_TEST');
    }

    public function test_custom_settings_actions_placeholders_and_helpers_are_localized()
    {
        app()->setLocale('uk');
        $admin = User::factory()->create(['role' => 'admin']);
        $component = Livewire::actingAs($admin)->test(Settings::class);
        $ukTranslations = require base_path('lang/uk/settings.php');

        $form = $component->instance()->getForm('form');
        $schema = $form->getComponents();

        $foundFields = [];
        $placeholders = [];
        $search = function ($components) use (&$search, &$foundFields, &$placeholders) {
            foreach ($components as $c) {
                if (method_exists($c, 'getName') && $c->getName()) {
                    $foundFields[$c->getName()] = $c;
                }

                if ($c instanceof Placeholder) {
                    $placeholders[] = $c;
                }

                if ($c instanceof Actions) {
                    foreach ($c->getChildComponents() as $action) {
                        if (method_exists($action, 'getName') && $action->getName()) {
                            $foundFields[$action->getName()] = $action;
                        }
                    }
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };
        $search($schema);

        $this->assertArrayHasKey('mail_smtp_password', $foundFields);
        $this->assertArrayHasKey('zabbix_api_token', $foundFields);
        $this->assertArrayHasKey('znuny_password', $foundFields);

        $this->assertEquals($ukTranslations['settings_page']['fields']['mail_smtp_password']['placeholder'], $foundFields['mail_smtp_password']->getPlaceholder());
        $this->assertEquals($ukTranslations['settings_page']['fields']['zabbix_api_token']['placeholder'], $foundFields['zabbix_api_token']->getPlaceholder());
        $this->assertEquals($ukTranslations['settings_page']['fields']['znuny_password']['placeholder'], $foundFields['znuny_password']->getPlaceholder());

        $this->assertArrayHasKey('testZabbixConnection', $foundFields);
        $this->assertEquals($ukTranslations['settings_page']['actions']['test_zabbix_api']['label'], $foundFields['testZabbixConnection']->getLabel());

        $this->assertArrayHasKey('testZnunyConnection_Credentials', $foundFields);
        $this->assertEquals($ukTranslations['settings_page']['actions']['test_znuny_api']['label'], $foundFields['testZnunyConnection_Credentials']->getLabel());

        $zabbixDescription = $ukTranslations['settings_page']['actions']['test_zabbix_api']['description'];
        $znunyDescription = $ukTranslations['settings_page']['actions']['test_znuny_api']['description'];

        $foundZabbixDesc = false;
        $foundZnunyDesc = false;

        foreach ($placeholders as $p) {
            $content = (string) $p->getContent();
            if (str_contains($content, $zabbixDescription)) {
                $foundZabbixDesc = true;
            }
            if (str_contains($content, $znunyDescription)) {
                $foundZnunyDesc = true;
            }
        }

        $this->assertTrue($foundZabbixDesc, 'Zabbix action description placeholder not found.');
        $this->assertTrue($foundZnunyDesc, 'Znuny action description placeholder not found.');
    }

    public function test_ticket_default_rule_custom_fields_are_localized()
    {
        Setting::updateOrCreate(['key' => 'znuny_queue_from_host_regex'], ['type' => 'string', 'value' => 'RAW_QUEUE_REGEX']);
        Setting::updateOrCreate(['key' => 'znuny_customer_user_from_queue_template'], ['type' => 'string', 'value' => 'RAW_CUSTOMER_USER']);

        app()->setLocale('uk');
        $admin = User::factory()->create(['role' => 'admin']);
        $component = Livewire::actingAs($admin)->test(Settings::class);
        $ukTranslations = require base_path('lang/uk/settings.php');

        $form = $component->instance()->getForm('form');
        $schema = $form->getComponents();

        $foundFields = [];
        $search = function ($components) use (&$search, &$foundFields) {
            foreach ($components as $c) {
                if (method_exists($c, 'getName') && $c->getName()) {
                    $foundFields[$c->getName()] = $c;
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };
        $search($schema);

        $this->assertArrayHasKey('znuny_queue_from_host_regex', $foundFields);
        $this->assertArrayHasKey('znuny_customer_user_from_queue_template', $foundFields);

        $queueField = $foundFields['znuny_queue_from_host_regex'];
        $customerUserField = $foundFields['znuny_customer_user_from_queue_template'];

        $this->assertEquals($ukTranslations['settings_page']['fields']['znuny_queue_from_host_regex']['label'], $queueField->getLabel());
        $queueHelper = (string) $queueField->getChildComponents('below_content')[0]->getContent();
        $this->assertEquals($ukTranslations['settings_page']['fields']['znuny_queue_from_host_regex']['description'], $queueHelper);
        $this->assertStringContainsString('(?<queue>...)', $queueHelper);

        $this->assertEquals($ukTranslations['settings_page']['fields']['znuny_customer_user_from_queue_template']['label'], $customerUserField->getLabel());
        $customerUserHelper = (string) $customerUserField->getChildComponents('below_content')[0]->getContent();
        $this->assertEquals($ukTranslations['settings_page']['fields']['znuny_customer_user_from_queue_template']['description'], $customerUserHelper);
        $this->assertStringContainsString('<queue>', $customerUserHelper);
        $this->assertStringContainsString('CustomerUser', $customerUserHelper);

        $component->assertSet('data.znuny_queue_from_host_regex', 'RAW_QUEUE_REGEX');
        $component->assertSet('data.znuny_customer_user_from_queue_template', 'RAW_CUSTOMER_USER');
    }

    public function test_url_template_examples_are_localized_without_changing_values()
    {
        Setting::updateOrCreate(['key' => 'zabbix_problem_url_template'], ['type' => 'string', 'value' => 'http://my-zabbix/?triggerid={trigger_id}']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_url_template'], ['type' => 'string', 'value' => 'http://my-znuny/?ticketid={ticket_id}']);

        app()->setLocale('uk');
        $admin = User::factory()->create(['role' => 'admin']);
        $component = Livewire::actingAs($admin)->test(Settings::class);
        $ukTranslations = require base_path('lang/uk/settings.php');

        $component->assertSee('{trigger_id}', false);
        $component->assertSee('https://zabbix.example.com/', false);

        $component->assertSee('{ticket_id}', false);
        $component->assertSee('https://znuny.example.com/', false);

        $component->assertSet('data.zabbix_problem_url_template', 'http://my-zabbix/?triggerid={trigger_id}');
        $component->assertSet('data.znuny_ticket_url_template', 'http://my-znuny/?ticketid={ticket_id}');
    }

    public function test_problem_highlighting_preview_uses_generic_localized_sample()
    {
        app()->setLocale('uk');
        $admin = User::factory()->create(['role' => 'admin']);
        $component = Livewire::actingAs($admin)->test(Settings::class);
        $ukTranslations = require base_path('lang/uk/settings.php');

        $label = $ukTranslations['settings_page']['fields']['problem_highlighting_preview']['label'];
        $component->assertSee($label, false);
        $component->assertSee('ExampleCompany server01[main]', false);
        $component->assertDontSee('Kreisel fastiv ipmi01[main]', false);
        $component->assertDontSee('settings.settings_page.fields.problem_highlighting_preview.sample', false);
    }

    public function test_queue_mapping_generated_note_uses_current_locale_without_rewriting_existing_notes()
    {
        $existingMappings = [
            [
                'host_prefix' => 'OldClient',
                'queue_name' => 'OldQueue',
                'note' => 'OLD_ENGLISH_NOTE',
            ],
        ];
        Setting::updateOrCreate(['key' => 'znuny_queue_host_mappings'], ['type' => 'json', 'value' => json_encode($existingMappings)]);

        app()->setLocale('uk');
        $admin = User::factory()->create(['role' => 'admin']);
        $component = Livewire::actingAs($admin)->test(Settings::class);

        // existing note must remain unchanged
        $data = $component->instance()->data;
        $mappings = array_values($data['znuny_queue_host_mappings'] ?? []);
        $this->assertEquals('OLD_ENGLISH_NOTE', $mappings[0]['note'] ?? null);

        $queueService = $this->createMock(ZnunyQueueService::class);
        $queueService->method('getQueues')->willReturn([['name' => 'ExistingQueue']]);

        $ruleService = $this->createMock(ZnunyTicketDefaultRuleService::class);
        $ruleService->method('detectQueueFromHost')->willReturn('NewClient');

        $problemCache = $this->createMock(ZabbixProblemCache::class);
        $problemCache->method('all')->willReturn([
            ['hosts' => [['name' => 'NewClient-Server01']]],
        ]);

        $serviceEn = new ZnunyQueueHostMappingService($queueService, $ruleService, $problemCache);

        // En
        app()->setLocale('en');
        $enTranslations = require base_path('lang/en/settings.php');
        $enResult = $serviceEn->scanMissingMappings([]);
        $this->assertNotEmpty($enResult['drafts']);
        $this->assertEquals($enTranslations['settings_page']['queue_mappings']['fields']['note']['generated_value'], $enResult['drafts'][0]['note']);

        $serviceUk = new ZnunyQueueHostMappingService($queueService, $ruleService, $problemCache);

        // Uk
        app()->setLocale('uk');
        $ukTranslations = require base_path('lang/uk/settings.php');
        $ukResult = $serviceUk->scanMissingMappings([]);
        $this->assertNotEmpty($ukResult['drafts']);
        $this->assertEquals($ukTranslations['settings_page']['queue_mappings']['fields']['note']['generated_value'], $ukResult['drafts'][0]['note']);
    }

    public function test_successful_save_emits_localized_notification_in_english()
    {
        app()->setLocale('en');
        $admin = User::factory()->create(['role' => 'admin']);
        Artisan::call('app:ensure-settings-defaults');

        $component = Livewire::actingAs($admin)->test(Settings::class);

        $component->fillForm([
            'zabbix_api_url' => 'http://example.com',
            'znuny_api_url' => 'http://example.com',
            'znuny_web_url' => 'http://example.com',
            'znuny_username' => 'user',
        ])->call('save')
            ->assertHasNoFormErrors();

        $enTranslations = require base_path('lang/en/settings.php');
        $expectedTitle = $enTranslations['settings_page']['notifications']['settings_saved']['title'];
        $this->assertNotEquals('settings.settings_page.notifications.settings_saved.title', $expectedTitle);

        $component->assertNotified(
            Notification::make()
                ->title($expectedTitle)
                ->success()
        );
    }

    public function test_successful_save_emits_localized_notification_in_ukrainian()
    {
        app()->setLocale('uk');
        $admin = User::factory()->create(['role' => 'admin', 'ui_locale' => 'uk']);
        Artisan::call('app:ensure-settings-defaults');

        $component = Livewire::actingAs($admin)->test(Settings::class);

        $component->fillForm([
            'zabbix_api_url' => 'http://example.com',
            'znuny_api_url' => 'http://example.com',
            'znuny_web_url' => 'http://example.com',
            'znuny_username' => 'user',
            'ui_locale' => 'uk',
        ])->call('save')
            ->assertHasNoFormErrors();

        $ukTranslations = require base_path('lang/uk/settings.php');
        $expectedTitle = $ukTranslations['settings_page']['notifications']['settings_saved']['title'];
        $this->assertNotEquals('settings.settings_page.notifications.settings_saved.title', $expectedTitle);

        $component->assertNotified(
            Notification::make()
                ->title($expectedTitle)
                ->success()
        );
    }
}
