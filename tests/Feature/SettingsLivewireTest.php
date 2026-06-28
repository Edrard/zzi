<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Services\Znuny\ZnunyClient;
use App\Support\Settings\DefaultSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsLivewireTest extends TestCase
{
    use RefreshDatabase;

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

                if ($type === 'Tab' && $label === 'Manual') {
                    $parentGroupName = 'Manual';
                }

                if ($name === 'manual_ticket_auto_close_enabled') {
                    $foundEnabled = true;
                }

                if ($name === 'manual_ticket_auto_close_schedule_mode') {
                    $foundMode = true;
                    if ($parentGroupName === 'Manual') {
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
        $this->assertTrue($automationTabModeFound, 'manual_ticket_auto_close_schedule_mode should be in Automation -> Manual tab');
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

                if ($name && $parentGroupName) {
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
            if (in_array($renderedSetting, ['znuny_queue_host_mappings', 'host_prefix', 'queue_name', 'note'])) {
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
                        if (! str_contains($name, 'testZnunyConnection') && ! str_starts_with($name, 'tester_help_') && $name !== 'testZabbixConnection' && $name !== 'zabbix_tester_help' && $name !== 'host_prefix' && $name !== 'queue_name' && $name !== 'note' && $name !== 'auto_tickets_placeholder') {
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
        $ignoredKeys = ['znuny_default_agent_login', 'znuny_default_agent_name', 'manual_ticket_auto_close_enabled'];
        foreach ($ignoredKeys as $key) {
            $this->assertContains($key, $defaultSettings, "Ignored setting key '{$key}' is missing from DefaultSettings registry.");
        }
    }
}
