<?php

namespace Tests\Feature\Console;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureTicketWorkspaceDefaultsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_missing_defaults()
    {
        $this->assertDatabaseMissing('settings', ['key' => 'znuny_ticket_workspace_enabled']);

        $this->artisan('settings:ensure-ticket-workspace-defaults')
            ->assertSuccessful();

        $this->assertDatabaseHas('settings', [
            'key' => 'znuny_ticket_workspace_enabled',
            'value' => 'true',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'znuny_ticket_cache_ttl_minutes',
            'value' => '10',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'znuny_ticket_workspace_active_state_type_ids',
            'value' => '["new","open","pending_reminder","pending_auto"]',
        ]);
    }

    public function test_it_deletes_obsolete_keys()
    {
        Setting::create(['key' => 'znuny_ticket_cache_ttl_seconds', 'value' => '1800', 'type' => 'integer']);
        Setting::create(['key' => 'znuny_ticket_cache_closed_ttl_seconds', 'value' => '172800', 'type' => 'integer']);
        Setting::create(['key' => 'znuny_ticket_cache_active_state_types', 'value' => 'new, pending reminder, pending auto', 'type' => 'string']);

        $this->artisan('settings:ensure-ticket-workspace-defaults')
            ->assertSuccessful();

        $this->assertDatabaseMissing('settings', ['key' => 'znuny_ticket_cache_ttl_seconds']);
        $this->assertDatabaseMissing('settings', ['key' => 'znuny_ticket_cache_closed_ttl_seconds']);
        $this->assertDatabaseMissing('settings', ['key' => 'znuny_ticket_cache_active_state_types']);
    }

    public function test_it_does_not_overwrite_existing_new_keys()
    {
        Setting::create([
            'key' => 'znuny_ticket_cache_ttl_minutes',
            'value' => '45',
            'type' => 'integer',
        ]);

        $this->artisan('settings:ensure-ticket-workspace-defaults')
            ->assertSuccessful();

        $this->assertDatabaseHas('settings', [
            'key' => 'znuny_ticket_cache_ttl_minutes',
            'value' => '45',
        ]);
    }
}
