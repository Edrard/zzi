<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Settings\DefaultSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZnunyTicketArticleCacheSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_settings_contains_article_cache_ttl()
    {
        $defaults = DefaultSettings::all();

        $articleSetting = collect($defaults)->firstWhere('key', 'znuny_ticket_article_cache_ttl_minutes');

        $this->assertNotNull($articleSetting);
        $this->assertEquals('15', $articleSetting['value']);
        $this->assertEquals('integer', $articleSetting['type']);
        $this->assertEquals('Lifetime in minutes for cached Znuny ticket article data. Set to 0 to bypass persistent ticket article caching.', $articleSetting['description']);
    }

    public function test_migration_inserts_setting_when_missing()
    {
        // First delete it if it exists from seeders
        Setting::where('key', 'znuny_ticket_article_cache_ttl_minutes')->delete();

        // Run the specific migration
        $migrationPath = database_path('migrations/2026_07_15_130000_add_znuny_article_cache_setting.php');
        $migration = require $migrationPath;
        $migration->up();

        $this->assertDatabaseHas('settings', [
            'key' => 'znuny_ticket_article_cache_ttl_minutes',
            'value' => '15',
            'type' => 'integer',
        ]);
    }

    public function test_migration_does_not_overwrite_existing_value()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_ticket_article_cache_ttl_minutes'],
            [
                'value' => '30',
                'type' => 'string',
                'description' => 'Custom description',
            ]
        );

        $migrationPath = database_path('migrations/2026_07_15_130000_add_znuny_article_cache_setting.php');
        $migration = require $migrationPath;
        $migration->up();

        $this->assertDatabaseHas('settings', [
            'key' => 'znuny_ticket_article_cache_ttl_minutes',
            'value' => '30',
            'type' => 'string',
            'description' => 'Custom description',
        ]);
    }

    public function test_migration_rollback_is_non_destructive()
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_article_cache_ttl_minutes'], ['value' => '60']);
        Setting::updateOrCreate(['key' => 'other_setting'], ['value' => 'test']);

        $migrationPath = database_path('migrations/2026_07_15_130000_add_znuny_article_cache_setting.php');
        $migration = require $migrationPath;
        $migration->down();

        $this->assertDatabaseHas('settings', ['key' => 'znuny_ticket_article_cache_ttl_minutes']);
        $this->assertDatabaseHas('settings', ['key' => 'other_setting']);
    }
}
