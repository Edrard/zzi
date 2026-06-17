<?php

namespace Tests\Feature;

use App\Filament\Pages\CurrentZabbixProblems;
use App\Models\Setting;
use App\Models\User;
use App\Models\ZabbixTicket;
use App\Services\SettingsService;
use App\Services\Zabbix\ZabbixProblemCache;
use App\Services\Znuny\ZnunyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class CurrentZabbixProblemsTicketModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::updateOrCreate(['key' => 'znuny_default_agent_id'], ['value' => '10']);
        Setting::updateOrCreate(['key' => 'znuny_queue_from_host_regex'], ['value' => '^(?<queue>[^\s]+)', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'znuny_customer_user_from_queue_template'], ['value' => '<queue>Clients', 'type' => 'string']);
        Cache::flush();

        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => '1001',
                'name' => 'TestCompany CPU Load',
                'host_name' => 'TestCompany swiss test01',
                'severity' => 4,
                'host_ip' => '192.168.1.10',
                'hosts' => [
                    [
                        'name' => 'TestCompany swiss test01 Display',
                        'host' => 'TestCompany swiss test01',
                        'hostid' => '2001',
                        'status' => '0',
                    ],
                ],
            ],
            [
                'eventid' => '1002',
                'name' => 'No IP problem',
                'host_name' => 'TestCompany swiss test02',
                'severity' => 4,
                'host_ip' => null,
                'hosts' => [
                    [
                        'name' => 'TestCompany swiss test02 Display',
                        'host' => 'TestCompany swiss test02',
                        'hostid' => '2002',
                        'status' => '0',
                    ],
                ],
            ],
        ], 3600);
    }

    public function test_expanded_problem_shows_ip_address_when_exists()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->assertSeeHtml('IP Address:</strong> 192.168.1.10');
    }

    public function test_expanded_problem_hides_ip_address_when_missing()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class);

        // It should NOT see an IP Address row for event 1002
        // Since we assert the HTML output, we know '192.168.1.10' is there, but for 1002 it's absent.
        // We can't strictly assert the absence of a specific row easily without parsing, but we can assure
        // 'IP Address:' is only present once.
        $html = $component->html();
        $this->assertEquals(1, substr_count($html, 'IP Address:'));
    }

    public function test_viewer_cannot_open_modal()
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        Livewire::actingAs($viewer)
            ->test(CurrentZabbixProblems::class)
            ->call('openCreateTicketModal', '1001')
            ->assertForbidden();
    }

    public function test_admin_can_open_modal_and_see_defaults()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Mock Agent
        Http::fake([
            '*example.invalid/api/Agent*' => Http::response([
                'Agents' => [
                    ['UserID' => 10, 'UserLogin' => 'agent1', 'UserFullname' => 'Agent One', 'ValidID' => 1],
                ],
            ], 200),
            '*example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            '*example.invalid/api/QueueByName*' => Http::response([
                'Queue' => ['QueueID' => 1, 'Name' => 'TestCompany', 'FullName' => 'TestCompany'],
            ], 200),
            '*example.invalid/api/Queue?*' => Http::response([
                'Queues' => [
                    ['QueueID' => 1, 'Name' => 'TestCompany', 'ValidID' => 1],
                ],
            ], 200),
            '*example.invalid/api/CustomerUser*' => Http::response([
                'CustomerUser' => ['UserLogin' => 'TestCompanyClients', 'UserCustomerID' => 'testcompany'],
            ], 200),
        ]);

        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->call('openCreateTicketModal', '1001');

        $component->assertDispatched('open-modal')
            ->assertSet('ticketModalEventId', '1001')
            ->assertSet('ticketOwnerId', null)
            ->assertSet('ticketOwnerOptions.10', 'Agent One <agent1>')
            ->assertSet('ticketQueue', 'TestCompany')
            ->assertSet('ticketCustomerUser', 'TestCompanyClients');

    }

    public function test_existing_linked_ticket_blocks_create()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        ZabbixTicket::create([
            'zabbix_event_id' => '1001',
            'zabbix_host_name' => 'TestCompany',
            'zabbix_problem_name' => 'CPU Load',
            'znuny_ticket_id' => '1234',
            'znuny_ticket_number' => '2026000000000000',
        ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->call('openCreateTicketModal', '1001')
            ->assertNotDispatched('open-modal')
            ->assertNotified();
    }

    public function test_validate_ticket_success()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        Http::fake([
            '*example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            '*example.invalid/api/ValidateTicketCreate*' => Http::response([
                'Valid' => 1,
                'Errors' => [],
                'Warnings' => [],
            ], 200),
        ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->set('ticketOwnerId', '10')
            ->set('ticketQueue', 'TestCompany')
            ->set('ticketCustomerUser', 'TestCompanyClients')
            ->call('validateTicketData')
            ->assertSet('ticketValidationStatus', 'success')
            ->assertNotified();
    }

    public function test_validate_ticket_error()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        Http::fake([
            '*example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            '*example.invalid/api/ValidateTicketCreate*' => Http::response([
                'Valid' => 0,
                'Errors' => ['CustomerUser not found.'],
                'Warnings' => [],
            ], 200),
        ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->set('ticketOwnerId', '10')
            ->set('ticketQueue', 'TestCompany')
            ->set('ticketCustomerUser', 'InvalidClient')
            ->call('validateTicketData')
            ->assertSet('ticketValidationStatus', 'error')
            ->assertSet('ticketValidationErrors', ['CustomerUser not found.']);
    }

    public function test_validate_ticket_missing_fields()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            // intentionally leaving fields empty
            ->call('validateTicketData')
            ->assertNotSet('ticketValidationStatus', 'validating') // should return early
            ->assertNotified(); // should have danger notification
    }

    public function test_validate_ticket_exception()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        // Mock an exception on ValidateTicketCreate
        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldReceive('validateTicketCreate')
            ->andThrow(new \Exception('Connection timeout'));

        // ensure other HTTP requests needed for boot aren't broken by our mocking just the validate call
        // well we mocked the whole client, so other calls on client might fail if not mocked, but we only hit validate in this action.

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->set('ticketOwnerId', '10')
            ->set('ticketQueue', 'TestCompany')
            ->set('ticketCustomerUser', 'TestCompanyClients')
            ->call('validateTicketData')
            ->assertSet('ticketValidationStatus', 'error')
            ->assertSet('ticketValidationErrors', ['Connection timeout']);
    }
}
