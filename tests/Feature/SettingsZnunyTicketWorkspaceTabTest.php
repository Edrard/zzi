<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsZnunyTicketWorkspaceTabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('app:ensure-settings-defaults');
        SettingsService::clearAllCaches();
    }

    public function test_ticket_workspace_tab_schema_assertions()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $component = Livewire::actingAs($admin)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $sectionsByHeading = [];
        $fieldsByName = [];

        $search = function ($components, $inWorkspaceTab = false) use (&$search, &$sectionsByHeading, &$fieldsByName) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $label = method_exists($c, 'getLabel') ? $c->getLabel() : null;
                $heading = method_exists($c, 'getHeading') ? $c->getHeading() : null;
                $name = method_exists($c, 'getName') ? $c->getName() : null;

                $isThisTab = $inWorkspaceTab || ($type === 'Tab' && $label === 'Ticket Workspace');

                if ($isThisTab) {
                    if ($type === 'Section' && $heading) {
                        $sectionsByHeading[$heading] = $c;
                    }
                    if ($name) {
                        $fieldsByName[$name] = $c;
                    }
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $isThisTab);
                }
            }
        };

        $search($schema);

        // Section descriptions
        $this->assertEquals(
            'Controls the Redis-backed Ticket Workspace subsystem, including active and closed ticket synchronization, manual refresh operations, and cached ticket access.',
            $sectionsByHeading['Ticket Workspace']->getDescription()
        );
        $this->assertEquals(
            'Controls how active Znuny tickets are fetched and retained in Redis. Shorter refresh intervals and larger API batches provide fresher data but increase Znuny API and processing load.',
            $sectionsByHeading['Active Ticket Cache']->getDescription()
        );
        $this->assertEquals(
            'Controls how closed tickets are synchronized and retained for Ticket Workspace. Eligibility is based on the ticket creation time, not the actual close or last-modified time, so later edits do not cause very old closed tickets to appear as recent.',
            $sectionsByHeading['Recent Closed Tickets']->getDescription()
        );

        // Labels
        $expectedLabels = [
            'znuny_ticket_workspace_enabled' => 'Znuny Ticket Workspace Enabled',
            'znuny_ticket_workspace_active_state_type_ids' => 'Znuny Ticket Workspace Active State Type Ids',
            'znuny_ticket_cache_refresh_interval_minutes' => 'Znuny Ticket Cache Refresh Interval Minutes',
            'znuny_ticket_cache_default_limit' => 'Znuny Ticket Cache Default Limit',
            'znuny_ticket_cache_max_pages_per_run' => 'Znuny Ticket Cache Max Pages Per Run',
            'znuny_ticket_cache_ttl_minutes' => 'Znuny Ticket Cache Ttl Minutes',
            'znuny_closed_ticket_window_days' => 'Znuny Closed Ticket Window Days',
            'znuny_closed_ticket_small_sync_interval_minutes' => 'Znuny Closed Ticket Small Sync Interval Minutes',
        ];

        foreach ($expectedLabels as $key => $label) {
            $this->assertArrayHasKey($key, $fieldsByName, "Field $key is missing from Ticket Workspace tab");
            $this->assertEquals($label, $fieldsByName[$key]->getLabel());
        }

        // Helper texts
        $component->assertSee('Enable Redis-backed Ticket Workspace.');
        $component->assertSee('JSON array of active operational state type IDs.');
        $component->assertSee('Interval for the Ticket Workspace cache warmer in minutes.');
        $component->assertSee('Default page size for Znuny ticket cache warming/search.');
        $component->assertSee('Safety limit for paginated ZnunyTicketSearch cache warming.');
        $component->assertSee('Default TTL for cached active Znuny tickets in minutes.');
        $component->assertSee('Number of recent days to retain in the closed ticket cache.');
        $component->assertSee('Interval for small closed ticket sync in minutes.');
    }

    private function getValidSettingsPayload(array $overrides = []): array
    {
        $payload = [];
        $settings = Setting::all();
        foreach ($settings as $setting) {
            $value = $setting->value;
            if ($setting->type === 'boolean') {
                $value = $value === 'true';
            } elseif ($setting->type === 'integer') {
                $value = (int) $value;
            } elseif ($setting->type === 'json') {
                $value = json_decode($value, true);
            }
            $payload[$setting->key] = $value;
        }

        // Merge standard validation prerequisites explicitly to avoid depending on default migrations entirely
        $payload = array_merge($payload, [
            'zabbix_api_url' => 'http://new.com',
            'znuny_api_url' => 'http://znuny.com',
            'znuny_web_url' => 'http://znuny.com',
            'znuny_username' => 'testuser',
            'pagination_per_page_base' => 50,
            'mail_transport' => 'smtp',
            'mail_smtp_host' => 'host',
            'mail_from_address' => 'admin@localhost',
            'app_url' => 'http://localhost',
            'zabbix_attention_highlight_text_custom_hex' => '000000',
            'zabbix_attention_highlight_underline_custom_hex' => '000000',
        ], $overrides);

        return $payload;
    }

    public function test_max_pages_minimum_valid_value_saves_successfully()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm($this->getValidSettingsPayload([
                'znuny_ticket_cache_max_pages_per_run' => 1,
            ]))
            ->call('save')
            ->assertHasNoFormErrors();

        $setting = Setting::where('key', 'znuny_ticket_cache_max_pages_per_run')->first();
        $this->assertEquals('1', $setting->value);
        $this->assertEquals('integer', $setting->type);
    }

    public function test_max_pages_invalid_zero_fails_validation_and_does_not_overwrite()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Setting::updateOrCreate(['key' => 'znuny_ticket_cache_max_pages_per_run'], ['value' => '5', 'type' => 'integer']);
        SettingsService::clearAllCaches();

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm($this->getValidSettingsPayload([
                'znuny_ticket_cache_max_pages_per_run' => 0,
            ]))
            ->call('save')
            ->assertHasFormErrors(['znuny_ticket_cache_max_pages_per_run']);

        $setting = Setting::where('key', 'znuny_ticket_cache_max_pages_per_run')->first();
        $this->assertEquals('5', $setting->value);
    }
}
