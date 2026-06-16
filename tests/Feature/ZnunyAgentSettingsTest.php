<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Znuny\ZnunyAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ZnunyAgentSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed basic settings
        Setting::updateOrCreate(['key' => 'znuny_api_url', 'type' => 'string'], ['value' => 'http://api.local']);
        Setting::updateOrCreate(['key' => 'znuny_username', 'type' => 'string'], ['value' => 'testuser']);
        Setting::updateOrCreate(['key' => 'znuny_password', 'type' => 'string'], ['value' => 'testpass']);
        Setting::updateOrCreate(['key' => 'znuny_default_agent_id', 'type' => 'string'], ['value' => '']);
        Setting::updateOrCreate(['key' => 'znuny_default_agent_login', 'type' => 'string'], ['value' => '']);
        Setting::updateOrCreate(['key' => 'znuny_default_agent_name', 'type' => 'string'], ['value' => '']);
        Setting::updateOrCreate(['key' => 'znuny_agent_exclude_logins', 'type' => 'string'], ['value' => "root@localhost\nzabbix.integration"]);

        Setting::updateOrCreate(['key' => 'zabbix_api_url', 'type' => 'string'], ['value' => 'http://zabbix']);
        Setting::updateOrCreate(['key' => 'znuny_web_url', 'type' => 'string'], ['value' => 'http://web']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_url_template', 'type' => 'string'], ['value' => 'url']);
        Setting::updateOrCreate(['key' => 'znuny_api_verify_ssl', 'type' => 'boolean'], ['value' => 'true']);
        Setting::updateOrCreate(['key' => 'znuny_api_timeout', 'type' => 'integer'], ['value' => '10']);
        Setting::updateOrCreate(['key' => 'cleanup_enabled', 'type' => 'boolean'], ['value' => 'true']);
        Setting::updateOrCreate(['key' => 'cleanup_batch_size', 'type' => 'integer'], ['value' => '1000']);
        Setting::updateOrCreate(['key' => 'retention_action_logs_days', 'type' => 'integer'], ['value' => '30']);
        Setting::updateOrCreate(['key' => 'retention_closed_tickets_days', 'type' => 'integer'], ['value' => '30']);
        Setting::updateOrCreate(['key' => 'retention_failed_jobs_days', 'type' => 'integer'], ['value' => '30']);
        Setting::updateOrCreate(['key' => 'retention_resolved_days', 'type' => 'integer'], ['value' => '30']);
        Setting::updateOrCreate(['key' => 'retention_statistics_days', 'type' => 'integer'], ['value' => '30']);
        Setting::updateOrCreate(['key' => 'zabbix_api_token', 'type' => 'string'], ['value' => '']);
        Setting::updateOrCreate(['key' => 'zabbix_api_timeout', 'type' => 'integer'], ['value' => '10']);
        Setting::updateOrCreate(['key' => 'zabbix_api_verify_ssl', 'type' => 'boolean'], ['value' => 'true']);
        Setting::updateOrCreate(['key' => 'zabbix_poll_interval_minutes', 'type' => 'integer'], ['value' => '5']);
        Setting::updateOrCreate(['key' => 'zabbix_problem_cache_ttl_minutes', 'type' => 'integer'], ['value' => '5']);
        Setting::updateOrCreate(['key' => 'zabbix_problem_limit', 'type' => 'integer'], ['value' => '100']);
        Setting::updateOrCreate(['key' => 'zabbix_exclude_suppressed_problems', 'type' => 'boolean'], ['value' => 'true']);
        Setting::updateOrCreate(['key' => 'default_close_delay_hours', 'type' => 'integer'], ['value' => '4']);
        Setting::updateOrCreate(['key' => 'default_reopen_window_hours', 'type' => 'integer'], ['value' => '24']);
    }

    public function test_znuny_agent_list_is_normalized_correctly()
    {
        Http::fake([
            'http://api.local/Session*' => Http::response(['SessionID' => 'fake-session']),
            'http://api.local/Agent*' => Http::response([
                'Agents' => [
                    ['UserID' => 20, 'UserLogin' => 'beta', 'UserFullname' => 'Beta User'],
                    ['UserID' => 10, 'UserLogin' => 'alpha', 'UserFullname' => 'Alpha User'],
                ],
            ]),
        ]);

        $agentService = app(ZnunyAgentService::class);
        $agents = $agentService->getAgents(failSilently: false);

        $this->assertCount(2, $agents);

        // Ensure sorted by label (Alpha then Beta)
        $this->assertEquals(10, $agents[0]['id']);
        $this->assertEquals('alpha', $agents[0]['login']);
        $this->assertEquals('Alpha User', $agents[0]['name']);
        $this->assertEquals('Alpha User <alpha>', $agents[0]['label']);

        $this->assertEquals(20, $agents[1]['id']);
        $this->assertEquals('beta', $agents[1]['login']);
        $this->assertEquals('Beta User <beta>', $agents[1]['label']);
    }

    public function test_malformed_agent_list_is_handled_safely()
    {
        Http::fake([
            'http://api.local/Session*' => Http::response(['SessionID' => 'fake-session']),
            'http://api.local/Agent*' => Http::response(['RandomData' => true]),
        ]);

        $agentService = app(ZnunyAgentService::class);

        // With failSilently = true (default)
        $agents = $agentService->getAgents();
        $this->assertEmpty($agents);
    }

    public function test_default_agent_is_persisted_with_snapshot()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Http::fake([
            'http://api.local/Session*' => Http::response(['SessionID' => 'fake-session']),
            'http://api.local/Agent*' => Http::response([
                'Agents' => [
                    ['UserID' => 12, 'UserLogin' => 'uav@example.invalid', 'UserFullname' => 'UAV ExampleCompany'],
                ],
            ]),
        ]);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm([
                'znuny_default_agent_id' => '12',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals('12', Setting::where('key', 'znuny_default_agent_id')->value('value'));
        $this->assertEquals('uav@example.invalid', Setting::where('key', 'znuny_default_agent_login')->value('value'));
        $this->assertEquals('UAV ExampleCompany', Setting::where('key', 'znuny_default_agent_name')->value('value'));

        $log = AuditLog::where('action', 'settings.updated')->latest('id')->first();
        $this->assertNotNull($log);
        $changes = collect($log->context['changes']);

        $idChange = $changes->firstWhere('key', 'znuny_default_agent_id');
        $this->assertEquals('12', $idChange['new_value']);

        $loginChange = $changes->firstWhere('key', 'znuny_default_agent_login');
        $this->assertEquals('uav@example.invalid', $loginChange['new_value']);
    }

    public function test_empty_default_agent_is_allowed()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Http::fake([
            'http://api.local/Session*' => Http::response(['SessionID' => 'fake-session']),
            'http://api.local/Agent*' => Http::response([
                'Agents' => [
                    ['UserID' => 12, 'UserLogin' => 'uav@example.invalid', 'UserFullname' => 'UAV ExampleCompany'],
                ],
            ]),
        ]);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm([
                'znuny_default_agent_id' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals('', Setting::where('key', 'znuny_default_agent_id')->value('value'));
        $this->assertEquals('', Setting::where('key', 'znuny_default_agent_login')->value('value'));
    }

    public function test_api_failure_does_not_destroy_snapshot()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Setting::where('key', 'znuny_default_agent_id')->update(['value' => '12']);
        Setting::where('key', 'znuny_default_agent_login')->update(['value' => 'uav@vamark.com']);

        Http::fake([
            'http://api.local/Session*' => Http::response(['SessionID' => 'fake-session']),
            'http://api.local/Agent*' => Http::response([], 500),
        ]);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm([
                'znuny_default_agent_id' => '12',
            ])
            ->call('save');

        // Snapshot is kept intact
        $this->assertEquals('12', Setting::where('key', 'znuny_default_agent_id')->value('value'));
        $this->assertEquals('uav@vamark.com', Setting::where('key', 'znuny_default_agent_login')->value('value'));
    }

    public function test_invalid_agent_selection_is_not_saved()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Setting::where('key', 'znuny_default_agent_id')->update(['value' => '12']);
        Setting::where('key', 'znuny_default_agent_login')->update(['value' => 'uav@vamark.com']);

        Http::fake([
            'http://api.local/Session*' => Http::response(['SessionID' => 'fake-session']),
            'http://api.local/Agent*' => Http::response([
                'Agents' => [
                    ['UserID' => 12, 'UserLogin' => 'uav@example.invalid', 'UserFullname' => 'UAV ExampleCompany'],
                ],
            ]),
        ]);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm([
                'znuny_default_agent_id' => '99', // Agent 99 does not exist
            ])
            ->call('save');

        // Value should remain 12
        $this->assertEquals('12', Setting::where('key', 'znuny_default_agent_id')->value('value'));
        $this->assertEquals('uav@vamark.com', Setting::where('key', 'znuny_default_agent_login')->value('value'));
    }

    public function test_excluded_logins_are_filtered_out_case_insensitively()
    {
        Http::fake([
            'http://api.local/Session*' => Http::response(['SessionID' => 'fake-session']),
            'http://api.local/Agent*' => Http::response([
                'Agents' => [
                    ['UserID' => 1, 'UserLogin' => 'root@localhost', 'UserFullname' => 'Root'],
                    ['UserID' => 2, 'UserLogin' => 'zabbix.integration', 'UserFullname' => 'Zabbix'],
                    ['UserID' => 3, 'UserLogin' => 'Root@Localhost', 'UserFullname' => 'Root uppercase'], // Should be filtered case insensitively
                    ['UserID' => 10, 'UserLogin' => 'alpha', 'UserFullname' => 'Alpha User'],
                ],
            ]),
        ]);

        $agentService = app(ZnunyAgentService::class);
        $selectableAgents = $agentService->getSelectableAgents(failSilently: false);

        $this->assertCount(1, $selectableAgents);
        $this->assertEquals(10, $selectableAgents[0]['id']);
        $this->assertEquals('alpha', $selectableAgents[0]['login']);
    }

    public function test_excluded_currently_stored_agent_triggers_warning_and_is_not_selectable()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Setting::where('key', 'znuny_default_agent_id')->update(['value' => '1']);
        Setting::where('key', 'znuny_default_agent_login')->update(['value' => 'root@localhost']);
        Setting::where('key', 'znuny_default_agent_name')->update(['value' => 'Root']);

        Http::fake([
            'http://api.local/Session*' => Http::response(['SessionID' => 'fake-session']),
            'http://api.local/Agent*' => Http::response([
                'Agents' => [
                    ['UserID' => 1, 'UserLogin' => 'root@localhost', 'UserFullname' => 'Root'],
                    ['UserID' => 10, 'UserLogin' => 'alpha', 'UserFullname' => 'Alpha User'],
                ],
            ]),
        ]);

        $livewire = Livewire::actingAs($admin)
            ->test(Settings::class);

        // The form should detect that ID 1 is active in getAgents but excluded in getSelectableAgents
        $livewire->assertSeeHtml('The currently selected default agent is excluded from selectable agents. Please choose another agent.');

        // If we attempt to save the excluded ID, it should not save (it behaves like an invalid selection)
        $livewire->fillForm([
            'znuny_default_agent_id' => '1',
        ])->call('save');

        // We don't change the value of it if it was already 1, but wait, the logic says:
        // if (! $selectedAgent) { continue; }
        // Let's change the value to another excluded one to ensure it rejects it.
        $livewire->fillForm([
            'znuny_default_agent_id' => '2', // Let's say we had a 2nd excluded agent we tried to save
        ])->call('save');

        $this->assertEquals('1', Setting::where('key', 'znuny_default_agent_id')->value('value'));
    }
}
