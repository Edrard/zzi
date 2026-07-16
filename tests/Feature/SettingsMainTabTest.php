<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Services\Support\ApplicationLocaleService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsMainTabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('app:ensure-settings-defaults');
    }

    public function test_main_schema_is_rendered_in_correct_order_with_sections(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $mainFieldsOrder = [];
        $sectionsOrder = [];
        $cleanupFound = false;

        $search = function ($components, $inMainTab = false) use (&$search, &$mainFieldsOrder, &$sectionsOrder, &$cleanupFound) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                $label = method_exists($c, 'getLabel') ? $c->getLabel() : null;
                $heading = method_exists($c, 'getHeading') ? $c->getHeading() : null;

                $isThisTab = $inMainTab || ($type === 'Tab' && $label === 'Main');

                if ($isThisTab && $type === 'Section' && $heading) {
                    $sectionsOrder[] = $heading;
                }

                if ($isThisTab && $name) {
                    if (in_array($name, ['app_display_timezone', 'pagination_per_page_base', 'ui_locale'])) {
                        $mainFieldsOrder[] = $name;
                    }
                    if (in_array($name, ['cleanup_enabled', 'cleanup_batch_size'])) {
                        $cleanupFound = true;
                    }
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $isThisTab);
                }
            }
        };

        $search($schema);

        $this->assertEquals(['Application Display'], $sectionsOrder);
        $this->assertEquals(['app_display_timezone', 'pagination_per_page_base', 'ui_locale'], $mainFieldsOrder);
        $this->assertFalse($cleanupFound, 'Cleanup fields should not be present in Main tab.');
    }

    public function test_main_components_have_correct_type_and_behavior(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $componentsByName = [];

        $search = function ($components) use (&$search, &$componentsByName) {
            foreach ($components as $c) {
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                if ($name && in_array($name, ['app_display_timezone', 'pagination_per_page_base', 'ui_locale'])) {
                    $componentsByName[$name] = $c;
                }
                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };

        $search($schema);

        $timezone = $componentsByName['app_display_timezone'];
        $this->assertInstanceOf(Select::class, $timezone);
        $this->assertTrue($timezone->isSearchable());
        $this->assertArrayHasKey('Europe/Kyiv', $timezone->getOptions());
        $this->assertEquals('Display Time Zone', $timezone->getLabel());

        $pagination = $componentsByName['pagination_per_page_base'];
        $this->assertInstanceOf(TextInput::class, $pagination);
        $this->assertTrue($pagination->isNumeric());
        $this->assertEquals('Base Rows per Page', $pagination->getLabel());

        $uiLocale = $componentsByName['ui_locale'];
        $this->assertInstanceOf(Select::class, $uiLocale);
        $this->assertEquals(__('settings.general.main.ui_locale.label'), $uiLocale->getLabel());
        $this->assertEquals(app(ApplicationLocaleService::class)->options(), $uiLocale->getOptions());
        $this->assertTrue($uiLocale->isRequired());

        $component->assertSee('Time zone used only for dates and times shown in the administration interface. Stored timestamps, background processing, and scheduler timing are not changed.');
        $component->assertSee('Base number of rows used by paginated tables. Available page-size choices are generated as half of this value rounded up to the nearest multiple of 5, the base value, double the value, and triple the value. For example, 100 produces 50, 100, 200, and 300.');
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
            'ui_locale' => 'uk',
        ], $overrides);
    }

    public function test_persistence_of_main_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $payload = $this->getValidSettingsPayload([
            'app_display_timezone' => 'Europe/Kyiv',
            'pagination_per_page_base' => 25,
        ]);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm($payload)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals('Europe/Kyiv', Setting::where('key', 'app_display_timezone')->value('value'));
        $this->assertEquals('25', Setting::where('key', 'pagination_per_page_base')->value('value'));
        $this->assertEquals('uk', Setting::where('key', 'ui_locale')->value('value'));
    }
}
