<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Settings\DefaultSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZnunyLookupCacheSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_settings_contains_lookup_cache_ttl()
    {
        $defaults = DefaultSettings::all();

        $lookupSetting = collect($defaults)->firstWhere('key', 'znuny_lookup_cache_ttl_minutes');

        $this->assertNotNull($lookupSetting);
        $this->assertEquals('60', $lookupSetting['value']);
        $this->assertEquals('integer', $lookupSetting['type']);
        $this->assertEquals('Lifetime in minutes for reusable Znuny lookup data such as queue owners, CustomerUsers, states, priorities, types, filtered queues, and lookup candidates. Set to 0 to bypass persistent lookup caching.', $lookupSetting['description']);
    }

    public function test_migration_inserts_setting_when_missing()
    {
        // First delete it if it exists from seeders
        Setting::where('key', 'znuny_lookup_cache_ttl_minutes')->delete();

        // Run the specific migration
        $migrationPath = database_path('migrations/2026_07_15_120000_add_znuny_lookup_cache_setting.php');
        $migration = require $migrationPath;
        $migration->up();

        $this->assertDatabaseHas('settings', [
            'key' => 'znuny_lookup_cache_ttl_minutes',
            'value' => '60',
            'type' => 'integer',
        ]);
    }

    public function test_migration_does_not_overwrite_existing_value()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_lookup_cache_ttl_minutes'],
            [
                'value' => '30',
                'type' => 'string',
                'description' => 'Custom description',
            ]
        );

        $migrationPath = database_path('migrations/2026_07_15_120000_add_znuny_lookup_cache_setting.php');
        $migration = require $migrationPath;
        $migration->up();

        $this->assertDatabaseHas('settings', [
            'key' => 'znuny_lookup_cache_ttl_minutes',
            'value' => '30',
            'type' => 'string',
            'description' => 'Custom description',
        ]);
    }

    public function test_migration_rollback_is_non_destructive()
    {
        Setting::updateOrCreate(['key' => 'znuny_lookup_cache_ttl_minutes'], ['value' => '60']);
        Setting::updateOrCreate(['key' => 'other_setting'], ['value' => 'test']);

        $migrationPath = database_path('migrations/2026_07_15_120000_add_znuny_lookup_cache_setting.php');
        $migration = require $migrationPath;
        $migration->down();

        $this->assertDatabaseHas('settings', ['key' => 'znuny_lookup_cache_ttl_minutes']);
        $this->assertDatabaseHas('settings', ['key' => 'other_setting']);
    }
}
