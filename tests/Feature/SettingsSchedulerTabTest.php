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

class SettingsSchedulerTabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('app:ensure-settings-defaults');
    }

    public function test_scheduler_schema_is_rendered_in_correct_order_and_no_duplicates()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $schedulerFieldsOrder = [];
        $schedulerFieldCounts = [];
        $sectionsOrder = [];

        $search = function ($components, $inSchedulerTab = false, $currentSection = null) use (&$search, &$schedulerFieldsOrder, &$schedulerFieldCounts, &$sectionsOrder) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                $label = method_exists($c, 'getLabel') ? $c->getLabel() : null;
                $heading = method_exists($c, 'getHeading') ? $c->getHeading() : null;

                if ($type === 'Tab' && $label === 'Scheduler') {
                    $inSchedulerTab = true;
                }

                if ($inSchedulerTab && $type === 'Section' && $heading) {
                    $sectionsOrder[] = $heading;
                    $currentSection = $heading;
                }

                if ($inSchedulerTab && $name && str_starts_with($name, 'scheduled_tasks_')) {
                    $schedulerFieldsOrder[] = $name;
                    if (! isset($schedulerFieldCounts[$name])) {
                        $schedulerFieldCounts[$name] = 0;
                    }
                    $schedulerFieldCounts[$name]++;
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $inSchedulerTab, $currentSection);
                }
            }
        };

        $search($schema);

        $expectedFieldsOrder = [
            'scheduled_tasks_enabled',
            'scheduled_tasks_max_processed_per_run',
            'scheduled_tasks_command_runtime_seconds',
            'scheduled_tasks_pause_minutes',
            'scheduled_tasks_missed_run_max_age_days',
            'scheduled_tasks_auto_disable_on_failures',
            'scheduled_tasks_failure_threshold',
        ];

        $this->assertEquals($expectedFieldsOrder, $schedulerFieldsOrder);

        $expectedSectionsOrder = [
            'Scheduler Control',
            'Execution Limits',
            'Recovery and Catch-up',
            'Failure Protection',
        ];

        $this->assertEquals($expectedSectionsOrder, $sectionsOrder);

        foreach ($expectedFieldsOrder as $key) {
            $this->assertEquals(1, $schedulerFieldCounts[$key] ?? 0, "Field $key should be rendered exactly once.");
        }
    }

    public function test_scheduler_components_have_correct_type()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $componentsByName = [];

        $search = function ($components) use (&$search, &$componentsByName) {
            foreach ($components as $c) {
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                if ($name && str_starts_with($name, 'scheduled_tasks_')) {
                    $componentsByName[$name] = $c;
                }
                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };

        $search($schema);

        $this->assertInstanceOf(Toggle::class, $componentsByName['scheduled_tasks_enabled']);
        $this->assertInstanceOf(Toggle::class, $componentsByName['scheduled_tasks_auto_disable_on_failures']);

        $numericFields = [
            'scheduled_tasks_max_processed_per_run',
            'scheduled_tasks_command_runtime_seconds',
            'scheduled_tasks_pause_minutes',
            'scheduled_tasks_missed_run_max_age_days',
            'scheduled_tasks_failure_threshold',
        ];

        foreach ($numericFields as $key) {
            $this->assertInstanceOf(TextInput::class, $componentsByName[$key]);
            $this->assertTrue($componentsByName[$key]->isNumeric(), "Field $key should be numeric.");
        }
    }

    public function test_unknown_future_setting_appears_under_additional_settings()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::updateOrCreate(['key' => 'scheduled_tasks_unknown_future_setting'], ['value' => 'test', 'type' => 'string']);

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $unknownSettingFoundInSection = null;

        $search = function ($components, $inSchedulerTab = false, $currentSection = null) use (&$search, &$unknownSettingFoundInSection) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                $label = method_exists($c, 'getLabel') ? $c->getLabel() : null;
                $heading = method_exists($c, 'getHeading') ? $c->getHeading() : null;

                if ($type === 'Tab' && $label === 'Scheduler') {
                    $inSchedulerTab = true;
                }

                if ($inSchedulerTab && $type === 'Section' && $heading) {
                    $currentSection = $heading;
                }

                if ($inSchedulerTab && $name === 'scheduled_tasks_unknown_future_setting') {
                    $unknownSettingFoundInSection = $currentSection;
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $inSchedulerTab, $currentSection);
                }
            }
        };

        $search($schema);

        $this->assertEquals('Additional Scheduler Settings', $unknownSettingFoundInSection);
    }

    public function test_runtime_fields_remain_hidden()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $hiddenFieldsFound = [];

        $search = function ($components) use (&$search, &$hiddenFieldsFound) {
            foreach ($components as $c) {
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                if ($name && in_array($name, [
                    'scheduled_tasks_paused_until',
                    'scheduled_tasks_pause_reason',
                    'scheduled_tasks_disabled_reason',
                ])) {
                    $hiddenFieldsFound[] = $name;
                }
                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };

        $search($schema);

        $this->assertEmpty($hiddenFieldsFound, 'Runtime fields should not be present in the form schema.');
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

    public function test_persistence_of_scheduler_settings()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::updateOrCreate(['key' => 'scheduled_tasks_unknown_future_setting'], ['value' => 'old_value', 'type' => 'string']);

        $payload = $this->getValidSettingsPayload([
            'scheduled_tasks_enabled' => false,
            'scheduled_tasks_max_processed_per_run' => 150,
            'scheduled_tasks_command_runtime_seconds' => 120,
            'scheduled_tasks_pause_minutes' => 15,
            'scheduled_tasks_missed_run_max_age_days' => 5,
            'scheduled_tasks_auto_disable_on_failures' => false,
            'scheduled_tasks_failure_threshold' => 10,
            'scheduled_tasks_unknown_future_setting' => 'new_value',
        ]);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm($payload)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals('false', Setting::where('key', 'scheduled_tasks_enabled')->value('value'));
        $this->assertEquals('150', Setting::where('key', 'scheduled_tasks_max_processed_per_run')->value('value'));
        $this->assertEquals('120', Setting::where('key', 'scheduled_tasks_command_runtime_seconds')->value('value'));
        $this->assertEquals('15', Setting::where('key', 'scheduled_tasks_pause_minutes')->value('value'));
        $this->assertEquals('5', Setting::where('key', 'scheduled_tasks_missed_run_max_age_days')->value('value'));
        $this->assertEquals('false', Setting::where('key', 'scheduled_tasks_auto_disable_on_failures')->value('value'));
        $this->assertEquals('10', Setting::where('key', 'scheduled_tasks_failure_threshold')->value('value'));
        $this->assertEquals('new_value', Setting::where('key', 'scheduled_tasks_unknown_future_setting')->value('value'));
    }
}
