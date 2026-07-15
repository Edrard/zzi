<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsCacheTabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('app:ensure-settings-defaults');
    }

    public function test_cache_schema_is_rendered_in_correct_order_and_no_duplicates()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $cacheFieldsOrder = [];
        $cacheFieldCounts = [];
        $sectionsOrder = [];
        $unwantedFieldsFound = [];

        $unwantedKeys = [
            'znuny_ticket_workspace_enabled',
            'znuny_ticket_workspace_active_state_type_ids',
            'znuny_ticket_workspace_default_per_page',
            'znuny_ticket_cache_refresh_interval_minutes',
            'znuny_ticket_cache_ttl_minutes',
            'znuny_ticket_cache_default_limit',
            'znuny_ticket_cache_max_pages_per_run',
            'znuny_closed_ticket_window_days',
            'znuny_closed_ticket_small_sync_interval_minutes',
            'znuny_ticket_workspace_sync_audit_enabled',
            'zabbix_problem_cache_ttl_minutes',
        ];

        $search = function ($components, $inCacheTab = false) use (&$search, &$cacheFieldsOrder, &$cacheFieldCounts, &$sectionsOrder, &$unwantedFieldsFound, $unwantedKeys) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                $label = method_exists($c, 'getLabel') ? $c->getLabel() : null;
                $heading = method_exists($c, 'getHeading') ? $c->getHeading() : null;

                $isThisTab = $inCacheTab || ($type === 'Tab' && $label === 'Cache');

                if ($isThisTab && $type === 'Section' && $heading) {
                    $sectionsOrder[] = $heading;
                }

                if ($isThisTab && $name) {
                    if (in_array($name, [
                        'znuny_agent_cache_ttl_minutes',
                        'znuny_queue_cache_ttl_minutes',
                        'znuny_ticket_snapshot_cache_ttl_minutes',
                    ])) {
                        $cacheFieldsOrder[] = $name;
                        if (! isset($cacheFieldCounts[$name])) {
                            $cacheFieldCounts[$name] = 0;
                        }
                        $cacheFieldCounts[$name]++;
                    }

                    if (in_array($name, $unwantedKeys)) {
                        $unwantedFieldsFound[] = $name;
                    }
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $isThisTab);
                }
            }
        };

        $search($schema);

        $expectedFieldsOrder = [
            'znuny_agent_cache_ttl_minutes',
            'znuny_queue_cache_ttl_minutes',
            'znuny_ticket_snapshot_cache_ttl_minutes',
        ];

        $this->assertEquals($expectedFieldsOrder, $cacheFieldsOrder);

        $expectedSectionsOrder = [
            'Znuny Reference Data',
            'Znuny Linked Ticket Data',
        ];

        $this->assertEquals($expectedSectionsOrder, $sectionsOrder);

        foreach ($expectedFieldsOrder as $key) {
            $this->assertEquals(1, $cacheFieldCounts[$key] ?? 0, "Field $key should be rendered exactly once.");
        }

        $this->assertEmpty($unwantedFieldsFound, 'Ticket Workspace or Zabbix problem cache fields should not be present in the Cache tab.');
    }

    public function test_cache_components_have_correct_type_and_labels_and_descriptions()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $componentsByName = [];
        $componentsByHeading = [];

        $search = function ($components) use (&$search, &$componentsByName, &$componentsByHeading) {
            foreach ($components as $c) {
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                if ($name && in_array($name, [
                    'znuny_agent_cache_ttl_minutes',
                    'znuny_queue_cache_ttl_minutes',
                    'znuny_ticket_snapshot_cache_ttl_minutes',
                ])) {
                    $componentsByName[$name] = $c;
                }

                if (class_basename($c) === 'Section' && method_exists($c, 'getHeading') && $c->getHeading()) {
                    $componentsByHeading[$c->getHeading()] = $c;
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };

        $search($schema);

        $this->assertEquals('Znuny Agent Cache Lifetime (minutes)', $componentsByName['znuny_agent_cache_ttl_minutes']->getLabel());
        $this->assertEquals('Znuny Queue Cache Lifetime (minutes)', $componentsByName['znuny_queue_cache_ttl_minutes']->getLabel());
        $this->assertEquals('Linked Ticket Snapshot Cache Lifetime (minutes)', $componentsByName['znuny_ticket_snapshot_cache_ttl_minutes']->getLabel());

        $this->assertEquals(
            'Configure how long reusable Znuny agent and queue reference data may be kept before the application requests updated data from Znuny. Shorter values provide fresher reference data but may increase API requests.',
            $componentsByHeading['Znuny Reference Data']->getDescription()
        );

        $this->assertEquals(
            'Configure caching associated with locally linked Znuny ticket data. These settings do not delete local ticket links and do not modify or delete tickets in Znuny.',
            $componentsByHeading['Znuny Linked Ticket Data']->getDescription()
        );

        // Helper text assertions
        $component->assertSee('Configured lifetime for cached active Znuny agent data used by owner selectors and agent-name displays.');
        $component->assertSee('Configured lifetime for cached Znuny queue data used by queue selectors, queue detection, and queue-mapping validation.');
        $component->assertSee('Configured lifetime for cached linked-ticket snapshot data. A snapshot may include locally stored Znuny ticket details such as state, owner, queue, priority, and synchronization metadata. This setting does not control Ticket Workspace caching and does not delete local ticket links or data in Znuny.');
    }

    public function test_unknown_future_setting_appears_under_additional_cache_settings()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        // Simulate an unknown cache setting
        Setting::updateOrCreate(['key' => 'znuny_lookup_cache_ttl_minutes'], ['value' => '30', 'type' => 'integer']);

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $unknownSettingFoundInSection = null;

        $search = function ($components, $inCacheTab = false, $currentSection = null) use (&$search, &$unknownSettingFoundInSection) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                $label = method_exists($c, 'getLabel') ? $c->getLabel() : null;
                $heading = method_exists($c, 'getHeading') ? $c->getHeading() : null;

                $isThisTab = $inCacheTab || ($type === 'Tab' && $label === 'Cache');

                if ($isThisTab && $type === 'Section' && $heading) {
                    $currentSection = $heading;
                }

                if ($isThisTab && $name === 'znuny_lookup_cache_ttl_minutes') {
                    $unknownSettingFoundInSection = $currentSection;
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $isThisTab, $currentSection);
                }
            }
        };

        $search($schema);

        $this->assertEquals('Additional Cache Settings', $unknownSettingFoundInSection);
    }

    private function getValidSettingsPayload(array $overrides = []): array
    {
        return array_merge([
            'znuny_username' => 'testuser',
            'znuny_api_url' => 'http://api',
            'znuny_web_url' => 'http://web',
            'znuny_ticket_url_template' => 'url',
            'znuny_api_verify_ssl' => true,
            'znuny_api_timeout' => 10,

            'znuny_agent_cache_ttl_minutes' => 60,
            'znuny_queue_cache_ttl_minutes' => 60,
            'znuny_ticket_snapshot_cache_ttl_minutes' => 60,

            'zabbix_api_url' => 'http://new.com',
            'zabbix_api_token' => '',
            'zabbix_api_timeout' => 10,
            'zabbix_api_verify_ssl' => true,
            'zabbix_poll_interval_minutes' => 5,
            'zabbix_problem_cache_ttl_minutes' => 5,
            'zabbix_problem_limit' => 100,
            'zabbix_exclude_suppressed_problems' => true,

            'mail_transport' => 'smtp',
            'mail_smtp_host' => 'host',
            'mail_smtp_port' => 25,
            'mail_smtp_encryption' => 'tls',
            'mail_smtp_timeout_seconds' => 10,
            'mail_smtp_password' => '',
            'mail_smtp_password_clear' => false,
        ], $overrides);
    }

    public function test_persistence_of_cache_settings()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $payload = $this->getValidSettingsPayload([
            'znuny_agent_cache_ttl_minutes' => 45,
            'znuny_queue_cache_ttl_minutes' => 45,
            'znuny_ticket_snapshot_cache_ttl_minutes' => 45,
        ]);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm($payload)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals('45', Setting::where('key', 'znuny_agent_cache_ttl_minutes')->value('value'));
        $this->assertEquals('45', Setting::where('key', 'znuny_queue_cache_ttl_minutes')->value('value'));
        $this->assertEquals('45', Setting::where('key', 'znuny_ticket_snapshot_cache_ttl_minutes')->value('value'));
    }
}
