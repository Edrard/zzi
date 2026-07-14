<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsRetentionTabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('app:ensure-settings-defaults');
    }

    public function test_retention_schema_is_rendered_in_correct_order_and_no_duplicates()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $retentionFieldsOrder = [];
        $retentionFieldCounts = [];
        $sectionsOrder = [];
        $schedulerFound = false;

        $search = function ($components, $inRetentionTab = false, $currentSection = null) use (&$search, &$retentionFieldsOrder, &$retentionFieldCounts, &$sectionsOrder, &$schedulerFound) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                $label = method_exists($c, 'getLabel') ? $c->getLabel() : null;
                $heading = method_exists($c, 'getHeading') ? $c->getHeading() : null;

                $isThisTab = $inRetentionTab || ($type === 'Tab' && $label === 'Retention');

                if ($isThisTab && $type === 'Section' && $heading) {
                    $sectionsOrder[] = $heading;
                    $currentSection = $heading;
                }

                if ($isThisTab && $name) {
                    if (in_array($name, [
                        'cleanup_enabled',
                        'cleanup_batch_size',
                        'retention_resolved_days',
                        'retention_closed_tickets_days',
                        'retention_action_logs_days',
                        'scheduled_task_logs_retention_days',
                        'retention_failed_jobs_days',
                    ])) {
                        $retentionFieldsOrder[] = $name;
                        if (! isset($retentionFieldCounts[$name])) {
                            $retentionFieldCounts[$name] = 0;
                        }
                        $retentionFieldCounts[$name]++;
                    }
                    if ($name === 'scheduled_tasks_missed_run_max_age_days') {
                        $schedulerFound = true;
                    }
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $isThisTab, $currentSection);
                }
            }
        };

        $search($schema);

        $expectedFieldsOrder = [
            'cleanup_enabled',
            'cleanup_batch_size',
            'retention_resolved_days',
            'retention_closed_tickets_days',
            'retention_action_logs_days',
            'scheduled_task_logs_retention_days',
            'retention_failed_jobs_days',
        ];

        $this->assertEquals($expectedFieldsOrder, $retentionFieldsOrder);

        $expectedSectionsOrder = [
            'Cleanup Control',
            'Integration History',
            'Logs and Processing Records',
        ];

        $this->assertEquals($expectedSectionsOrder, $sectionsOrder);

        foreach ($expectedFieldsOrder as $key) {
            $this->assertEquals(1, $retentionFieldCounts[$key] ?? 0, "Field $key should be rendered exactly once.");
        }

        $this->assertFalse($schedulerFound, 'scheduled_tasks_missed_run_max_age_days must remain isolated from Retention tab.');
    }

    public function test_retention_components_have_correct_type_and_labels()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $componentsByName = [];

        $search = function ($components) use (&$search, &$componentsByName) {
            foreach ($components as $c) {
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                if ($name && in_array($name, [
                    'cleanup_enabled',
                    'cleanup_batch_size',
                    'retention_resolved_days',
                    'retention_closed_tickets_days',
                    'retention_action_logs_days',
                    'scheduled_task_logs_retention_days',
                    'retention_failed_jobs_days',
                ])) {
                    $componentsByName[$name] = $c;
                }
                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };

        $search($schema);

        $this->assertInstanceOf(Toggle::class, $componentsByName['cleanup_enabled']);
        $this->assertEquals('Automatic Local Data Cleanup', $componentsByName['cleanup_enabled']->getLabel());

        $numericFields = [
            'cleanup_batch_size' => 'Records per Cleanup Batch',
            'retention_resolved_days' => 'Resolved Problem History (days)',
            'retention_closed_tickets_days' => 'Closed Ticket Link History (days)',
            'retention_action_logs_days' => 'Action Log Retention (days)',
            'scheduled_task_logs_retention_days' => 'Scheduled Task Run Log Retention (days)',
            'retention_failed_jobs_days' => 'Failed Job Retention (days)',
        ];

        foreach ($numericFields as $key => $label) {
            $this->assertInstanceOf(TextInput::class, $componentsByName[$key]);
            $this->assertTrue($componentsByName[$key]->isNumeric());
            $this->assertEquals($label, $componentsByName[$key]->getLabel());
        }

        $componentsByHeading = [];
        $searchSections = function ($components) use (&$searchSections, &$componentsByHeading) {
            foreach ($components as $c) {
                if (class_basename($c) === 'Section' && method_exists($c, 'getHeading') && $c->getHeading()) {
                    $componentsByHeading[$c->getHeading()] = $c;
                }
                if (method_exists($c, 'getChildComponents')) {
                    $searchSections($c->getChildComponents());
                }
            }
        };
        $searchSections($schema);

        $this->assertEquals(
            'Controls how long this integration keeps local operational records and how scheduled cleanup removes records that exceed the retention periods configured below. These settings affect only local integration data and do not delete data from Zabbix or Znuny.',
            $componentsByHeading['Cleanup Control']->getDescription()
        );

        $component->assertSee('Enable scheduled cleanup of old local integration records. Disabling this option preserves all retention settings but prevents automatic deletion. This does not delete active Zabbix problems or Znuny tickets.');
        $component->assertSee('Maximum number of records removed from each cleanup category during one cleanup pass. Lower values reduce database load; higher values clear accumulated old data faster.');
        $component->assertSee('Number of days to keep local history for Zabbix problems after they become resolved. This does not delete problems, events, or history from Zabbix.');
        $component->assertSee('Number of days to keep local integration records and links for closed tickets. This does not delete tickets, articles, or history from Znuny.');
        $component->assertSee('Number of days to keep execution logs for scheduled Znuny task runs. Scheduled task definitions and pending scheduled work are not deleted by this retention setting.');
    }

    public function test_disabled_cleanup_visibility()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::updateOrCreate(['key' => 'cleanup_enabled'], ['value' => 'false', 'type' => 'boolean']);

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $cleanupBatchSizeVisible = false;

        $search = function ($components) use (&$search, &$cleanupBatchSizeVisible) {
            foreach ($components as $c) {
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                if ($name === 'cleanup_batch_size') {
                    $cleanupBatchSizeVisible = ! $c->isHidden();
                }
                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };

        $search($schema);

        $this->assertTrue($cleanupBatchSizeVisible, 'cleanup_batch_size should remain visible and editable even if cleanup_enabled is false.');
    }

    public function test_unknown_future_setting_appears_under_additional_settings()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::updateOrCreate(['key' => 'retention_unknown_future_days'], ['value' => '30', 'type' => 'integer']);

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $unknownSettingFoundInSection = null;

        $search = function ($components, $inRetentionTab = false, $currentSection = null) use (&$search, &$unknownSettingFoundInSection) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                $label = method_exists($c, 'getLabel') ? $c->getLabel() : null;
                $heading = method_exists($c, 'getHeading') ? $c->getHeading() : null;

                $isThisTab = $inRetentionTab || ($type === 'Tab' && $label === 'Retention');

                if ($isThisTab && $type === 'Section' && $heading) {
                    $currentSection = $heading;
                }

                if ($isThisTab && $name === 'retention_unknown_future_days') {
                    $unknownSettingFoundInSection = $currentSection;
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $isThisTab, $currentSection);
                }
            }
        };

        $search($schema);

        $this->assertEquals('Additional Retention Settings', $unknownSettingFoundInSection);
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
            'cleanup_enabled' => true,
            'cleanup_batch_size' => 1000,
            'retention_action_logs_days' => 30,
            'retention_closed_tickets_days' => 30,
            'retention_failed_jobs_days' => 30,
            'retention_resolved_days' => 30,
            'zabbix_api_url' => 'http://new.com',
            'zabbix_api_token' => '',
            'zabbix_api_timeout' => 10,
            'zabbix_api_verify_ssl' => true,
            'zabbix_poll_interval_minutes' => 5,
            'zabbix_problem_cache_ttl_minutes' => 5,
            'zabbix_problem_limit' => 100,
            'zabbix_exclude_suppressed_problems' => true,
            'default_close_delay_hours' => 4,
            'default_reopen_window_hours' => 24,

            'mail_transport' => 'smtp',
            'mail_smtp_host' => 'host',
            'mail_smtp_port' => 25,
            'mail_smtp_encryption' => 'tls',
            'mail_smtp_timeout_seconds' => 10,
            'mail_smtp_password' => '',
            'mail_smtp_password_clear' => false,
        ], $overrides);
    }

    public function test_persistence_of_retention_settings()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::updateOrCreate(['key' => 'retention_unknown_future_days'], ['value' => 'old_value', 'type' => 'integer']);

        $payload = $this->getValidSettingsPayload([
            'cleanup_enabled' => false,
            'cleanup_batch_size' => 500,
            'retention_resolved_days' => 45,
            'retention_closed_tickets_days' => 45,
            'retention_action_logs_days' => 45,
            'scheduled_task_logs_retention_days' => 45,
            'retention_failed_jobs_days' => 45,
            'retention_unknown_future_days' => 45,
        ]);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm($payload)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals('false', Setting::where('key', 'cleanup_enabled')->value('value'));
        $this->assertEquals('500', Setting::where('key', 'cleanup_batch_size')->value('value'));
        $this->assertEquals('45', Setting::where('key', 'retention_resolved_days')->value('value'));
        $this->assertEquals('45', Setting::where('key', 'retention_closed_tickets_days')->value('value'));
        $this->assertEquals('45', Setting::where('key', 'retention_action_logs_days')->value('value'));
        $this->assertEquals('45', Setting::where('key', 'scheduled_task_logs_retention_days')->value('value'));
        $this->assertEquals('45', Setting::where('key', 'retention_failed_jobs_days')->value('value'));
        $this->assertEquals('45', Setting::where('key', 'retention_unknown_future_days')->value('value'));
    }
}
