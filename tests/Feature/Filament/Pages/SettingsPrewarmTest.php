<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Pages\Settings;
use App\Models\User;
use App\Support\Settings\DefaultSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsPrewarmTest extends TestCase
{
    use RefreshDatabase;

    public function test_prewarm_defaults()
    {
        $all = DefaultSettings::all();
        $defaults = [];
        foreach ($all as $item) {
            $defaults[$item['key']] = $item['value'];
        }

        $this->assertEquals(5, (int) $defaults['znuny_prewarm_queues_interval_minutes']);
        $this->assertEquals(5, (int) $defaults['znuny_prewarm_agents_interval_minutes']);
        $this->assertEquals(60, (int) $defaults['znuny_prewarm_lookups_interval_minutes']);
        $this->assertEquals(30, (int) $defaults['znuny_prewarm_customer_users_interval_minutes']);
    }

    public function test_legacy_ttl_fields_absent()
    {
        $all = DefaultSettings::all();
        $defaults = [];
        foreach ($all as $item) {
            $defaults[$item['key']] = $item['value'];
        }

        $this->assertArrayNotHasKey('znuny_agent_cache_ttl_minutes', $defaults);
        $this->assertArrayNotHasKey('znuny_queue_cache_ttl_minutes', $defaults);
        $this->assertArrayNotHasKey('znuny_lookup_cache_ttl_minutes', $defaults);

        $this->assertArrayHasKey('znuny_ticket_article_cache_ttl_minutes', $defaults);
        $this->assertArrayHasKey('znuny_ticket_snapshot_cache_ttl_minutes', $defaults);
    }

    public function test_prewarm_settings_ui()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);

        // the three legacy TTL fields are absent from the rendered Settings form schema
        $component
            ->assertFormFieldDoesNotExist('znuny_agent_cache_ttl_minutes')
            ->assertFormFieldDoesNotExist('znuny_queue_cache_ttl_minutes')
            ->assertFormFieldDoesNotExist('znuny_lookup_cache_ttl_minutes');

        // four prewarm fields exist in the form schema
        $component
            ->assertFormFieldExists('znuny_prewarm_queues_interval_minutes')
            ->assertFormFieldExists('znuny_prewarm_agents_interval_minutes')
            ->assertFormFieldExists('znuny_prewarm_lookups_interval_minutes')
            ->assertFormFieldExists('znuny_prewarm_customer_users_interval_minutes');

        // Verify minimum value of 3 is enforced by attempting to set a lower value and saving
        $component->set('data.znuny_prewarm_queues_interval_minutes', 2)
            ->call('save')
            ->assertHasErrors(['data.znuny_prewarm_queues_interval_minutes']);

        // Settings clear action and three legacy Znuny clear actions are absent
        $component->assertDontSee('clearSettingsCache')
            ->assertDontSee('clearZnunyAgentCache')
            ->assertDontSee('clearZnunyQueueCache')
            ->assertDontSee('clearZnunyLookupCache');

        // ticket article clear action remains
        $component->assertSee('clearTicketArticleCache');
    }
}
