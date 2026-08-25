<?php

namespace Tests\Feature\Console;

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureSettingsDefaultsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_obsolete_keys_and_invalidates_cache()
    {
        Setting::create([
            'key' => 'znuny_ticket_cache_closed_ttl_minutes',
            'value' => '10',
            'type' => 'integer',
        ]);

        SettingsService::clearAllCaches();

        // Warm the cache
        $this->assertEquals('10', SettingsService::string('znuny_ticket_cache_closed_ttl_minutes'));

        $this->artisan('app:ensure-settings-defaults')
            ->assertSuccessful();

        $this->assertDatabaseMissing('settings', ['key' => 'znuny_ticket_cache_closed_ttl_minutes']);

        // Check if SettingsService sees the deletion immediately
        $this->assertNull(SettingsService::string('znuny_ticket_cache_closed_ttl_minutes'));
    }
}
