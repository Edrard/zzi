<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZnunyLookupCacheSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_migration_removes_all_three_legacy_settings()
    {
        Setting::updateOrCreate(['key' => 'znuny_queue_cache_ttl_minutes'], ['value' => '15', 'type' => 'integer']);
        Setting::updateOrCreate(['key' => 'znuny_agent_cache_ttl_minutes'], ['value' => '15', 'type' => 'integer']);
        Setting::updateOrCreate(['key' => 'znuny_lookup_cache_ttl_minutes'], ['value' => '60', 'type' => 'integer']);

        Setting::updateOrCreate(['key' => 'znuny_ticket_article_cache_ttl_minutes'], ['value' => '15', 'type' => 'integer']);

        $migrationPath = database_path('migrations/2026_08_07_200800_remove_legacy_znuny_reference_cache_settings.php');
        $migration = require $migrationPath;
        $migration->up();

        $this->assertDatabaseMissing('settings', ['key' => 'znuny_queue_cache_ttl_minutes']);
        $this->assertDatabaseMissing('settings', ['key' => 'znuny_agent_cache_ttl_minutes']);
        $this->assertDatabaseMissing('settings', ['key' => 'znuny_lookup_cache_ttl_minutes']);

        $this->assertDatabaseHas('settings', ['key' => 'znuny_ticket_article_cache_ttl_minutes']);
    }

    public function test_cleanup_migration_down_restores_all_three_legacy_settings()
    {
        Setting::whereIn('key', [
            'znuny_queue_cache_ttl_minutes',
            'znuny_agent_cache_ttl_minutes',
            'znuny_lookup_cache_ttl_minutes',
        ])->delete();

        $migrationPath = database_path('migrations/2026_08_07_200800_remove_legacy_znuny_reference_cache_settings.php');
        $migration = require $migrationPath;
        $migration->down();

        $this->assertDatabaseHas('settings', [
            'key' => 'znuny_queue_cache_ttl_minutes',
            'value' => '15',
            'type' => 'integer',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'znuny_agent_cache_ttl_minutes',
            'value' => '15',
            'type' => 'integer',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'znuny_lookup_cache_ttl_minutes',
            'value' => '60',
            'type' => 'integer',
        ]);
    }
}
