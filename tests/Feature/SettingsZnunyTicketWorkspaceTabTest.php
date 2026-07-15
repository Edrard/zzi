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
            'znuny_ticket_workspace_enabled' => 'Enable Ticket Workspace',
            'znuny_ticket_workspace_active_state_type_ids' => 'Active State Types',
            'znuny_ticket_cache_refresh_interval_minutes' => 'Active Cache Refresh Interval (Minutes)',
            'znuny_ticket_cache_default_limit' => 'Znuny API Fetch Batch Size',
            'znuny_ticket_cache_max_pages_per_run' => 'Max Pages Per Run',
            'znuny_ticket_cache_ttl_minutes' => 'Active Ticket Cache TTL (Minutes)',
            'znuny_closed_ticket_window_days' => 'Closed Ticket Creation Window (Days)',
            'znuny_closed_ticket_small_sync_interval_minutes' => 'Recent Closed Tickets Sync Interval (Minutes)',
        ];

        foreach ($expectedLabels as $key => $label) {
            $this->assertArrayHasKey($key, $fieldsByName, "Field $key is missing from Ticket Workspace tab");
            $this->assertEquals($label, $fieldsByName[$key]->getLabel());
        }

        // Helper texts
        $component->assertSee('Master switch for the entire Ticket Workspace subsystem. When disabled, scheduled and manual synchronization, individual ticket refreshes, and cached ticket reads are blocked. Existing cached data is retained and becomes available again after the feature is re-enabled.');
        $component->assertSee('Select the Znuny state types included in the active ticket working set. These values are state type names, not numeric IDs. Changes apply to the next active-ticket cache refresh.');
        $component->assertSee('How often the scheduled active-ticket cache warmer is allowed to run. The scheduler checks regularly but skips warming until this interval has elapsed; manual refreshes are not limited by this value. Lower values increase Znuny API load.');
        $component->assertSee('Number of active tickets requested from Znuny in each API page during cache warming. This does not control the number of rows displayed in Ticket Workspace. Larger values reduce request count but increase response size and processing load.');
        $component->assertSee('Maximum number of Znuny API pages processed during one active-ticket cache warming run. The approximate upper limit per run is Znuny API Fetch Batch Size × Max Pages Per Run; fewer pages are requested when Znuny has no more results.');
        $component->assertSee('Base Redis lifetime for cached active tickets. The application may automatically increase the effective TTL so cached data does not expire before the next scheduled refresh and UI polling cycle. Increasing this value retains stale active-ticket data longer if synchronization stops.');
        $component->assertSee('Closed tickets are cached only when their Created timestamp falls within this number of days. The window is not based on the actual close time because Znuny does not provide a sufficiently reliable close timestamp for this workflow. Reducing the value does not immediately remove entries already retained in Redis; cached entries expire naturally and may remain physically stored for up to six times this window.');
        $component->assertSee('How often the scheduled small synchronization checks Znuny for recently changed closed tickets and refreshes the closed-ticket cache. Only tickets whose Created timestamp falls inside the configured creation window are stored. Lower values increase Znuny API load, and synchronization does not run while Ticket Workspace is disabled.');
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
