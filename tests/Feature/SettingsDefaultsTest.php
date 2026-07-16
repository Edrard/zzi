<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Settings\DefaultSettings;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SettingsDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_registry_has_empty_endpoints_and_no_vamark_domains()
    {
        $defaults = DefaultSettings::all();

        $emptyEndpoints = [
            'zabbix_api_url',
            'znuny_api_url',
            'znuny_web_url',
            'znuny_ticket_url_template',
            'zabbix_problem_url_template',
        ];

        foreach ($defaults as $default) {
            if (in_array($default['key'], $emptyEndpoints)) {
                $this->assertEquals('', $default['value'], "Endpoint {$default['key']} must be empty");
            }

            $this->assertStringNotContainsString('vamark', strtolower($default['value']), "Default value for {$default['key']} contains vamark domain");
            $this->assertStringNotContainsString('vamark', strtolower($default['description']), "Default description for {$default['key']} contains vamark domain");
        }
    }

    public function test_settings_seeder_creates_all_registry_keys()
    {
        $this->seed(SettingsSeeder::class);

        $defaults = DefaultSettings::all();
        $this->assertGreaterThan(0, count($defaults));

        foreach ($defaults as $default) {
            $this->assertDatabaseHas('settings', [
                'key' => $default['key'],
                'value' => $default['value'],
            ]);
        }
    }

    public function test_ensure_settings_defaults_command_creates_missing_without_overwriting()
    {
        // Setup an existing setting with a custom value
        Setting::create([
            'key' => 'cleanup_batch_size',
            'value' => '500',
            'type' => 'integer',
            'description' => 'Custom description',
        ]);

        $exitCode = Artisan::call('app:ensure-settings-defaults');
        $this->assertEquals(0, $exitCode);

        // Check that the custom value was NOT overwritten
        $this->assertDatabaseHas('settings', [
            'key' => 'cleanup_batch_size',
            'value' => '500',
        ]);

        // Check that a missing setting WAS created
        $this->assertDatabaseHas('settings', [
            'key' => 'cleanup_enabled',
            'value' => 'true',
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('Done!', $output);
    }

    public function test_default_registry_contains_pagination_base_setting()
    {
        $defaults = DefaultSettings::all();
        $this->assertContains('pagination_per_page_base', array_column($defaults, 'key'));
    }

    public function test_ensure_settings_defaults_deletes_obsolete_closed_ttl()
    {
        Setting::create([
            'key' => 'znuny_ticket_cache_closed_ttl_minutes',
            'value' => '1440',
            'type' => 'integer',
        ]);

        $this->assertDatabaseHas('settings', ['key' => 'znuny_ticket_cache_closed_ttl_minutes']);

        Artisan::call('app:ensure-settings-defaults');

        $this->assertDatabaseMissing('settings', ['key' => 'znuny_ticket_cache_closed_ttl_minutes']);

        $defaults = DefaultSettings::all();
        $this->assertNotContains('znuny_ticket_cache_closed_ttl_minutes', array_column($defaults, 'key'));
    }

    public function test_ui_locale_defaults_are_correct(): void
    {
        $defaults = DefaultSettings::all();
        $uiLocaleDefaults = array_filter($defaults, fn ($d) => $d['key'] === 'ui_locale');

        $this->assertCount(1, $uiLocaleDefaults);
        $this->assertEquals('en', reset($uiLocaleDefaults)['value']);
        $this->assertEquals('string', reset($uiLocaleDefaults)['type']);
    }

    public function test_ensure_settings_defaults_creates_missing_ui_locale(): void
    {
        $this->assertDatabaseMissing('settings', ['key' => 'ui_locale']);
        Artisan::call('app:ensure-settings-defaults');
        $this->assertDatabaseHas('settings', ['key' => 'ui_locale', 'value' => 'en']);
    }

    public function test_ensure_settings_defaults_does_not_overwrite_ui_locale(): void
    {
        Setting::create([
            'key' => 'ui_locale',
            'value' => 'uk',
            'type' => 'string',
        ]);

        Artisan::call('app:ensure-settings-defaults');
        $this->assertDatabaseHas('settings', ['key' => 'ui_locale', 'value' => 'uk']);
    }
}
