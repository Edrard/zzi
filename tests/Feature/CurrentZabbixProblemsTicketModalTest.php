<?php

namespace Tests\Feature;

use App\Filament\Pages\CurrentZabbixProblems;
use App\Models\Setting;
use App\Models\User;
use App\Models\ZabbixTicket;
use App\Services\SettingsService;
use App\Services\Zabbix\ZabbixProblemCache;
use App\Services\Znuny\ZnunyAgentService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
                'objectid' => '2001',
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
                'objectid' => '2002',
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
            ->assertSeeHtml('IP Address:')
            ->assertSeeHtml('192.168.1.10');
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

    public function test_viewer_cannot_create_ticket()
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        Livewire::actingAs($viewer)
            ->test(CurrentZabbixProblems::class)
            ->call('createZnunyTicket')
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

    public function test_linked_ticket_owner_display_uses_name_if_present()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        ZabbixTicket::create([
            'zabbix_event_id' => '1001',
            'zabbix_host_name' => 'TestCompany',
            'zabbix_problem_name' => 'CPU Load',
            'znuny_ticket_id' => '1234',
            'znuny_ticket_number' => '2026000000000000',
            'znuny_owner_id' => 99,
            'znuny_owner_name' => 'Jane Doe',
        ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->assertSeeHtml('<strong>Owner:</strong> Jane Doe');
    }

    public function test_linked_ticket_owner_display_uses_cached_agent_label()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Mock the agent service to return known agents
        $agentServiceMock = $this->mock(ZnunyAgentService::class);
        $agentServiceMock->shouldReceive('getAgents')
            ->andReturn([
                ['id' => 55, 'login' => 'agent.smith', 'label' => 'Agent Smith'],
            ]);

        ZabbixTicket::create([
            'zabbix_event_id' => '1001',
            'zabbix_host_name' => 'TestCompany',
            'zabbix_problem_name' => 'CPU Load',
            'znuny_ticket_id' => '1234',
            'znuny_ticket_number' => '2026000000000000',
            'znuny_owner_id' => 55,
            'znuny_owner_name' => null,
        ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->assertSeeHtml('<strong>Owner:</strong> Agent Smith');
    }

    public function test_linked_ticket_owner_display_falls_back_to_id()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Mock the agent service to return empty or missing agent
        $agentServiceMock = $this->mock(ZnunyAgentService::class);
        $agentServiceMock->shouldReceive('getAgents')->andReturn([]);

        ZabbixTicket::create([
            'zabbix_event_id' => '1001',
            'zabbix_host_name' => 'TestCompany',
            'zabbix_problem_name' => 'CPU Load',
            'znuny_ticket_id' => '1234',
            'znuny_ticket_number' => '2026000000000000',
            'znuny_owner_id' => 99,
            'znuny_owner_name' => null,
        ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->assertSeeHtml('<strong>Owner:</strong> Owner ID: 99');
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

    public function test_create_ticket_success()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Since we are mocking the service directly, we don't need the client/fakes unless the modal boot requires it.
        // Modal boot requires QueueByName, CustomerUser, etc. But this test acts after boot when we already have state.
        $serviceMock = $this->mock(ZnunyTicketCreationService::class);
        $serviceMock->shouldReceive('createTicketForProblem')
            ->once()
            ->with(
                '1001',
                'TestCompany swiss test01',
                'TestCompany CPU Load',
                '10',
                'TestCompany',
                'TestCompanyClients',
                'Test Title',
                'Test Subject',
                'Test Body',
                '2001',
                '3001',
                null
            )
            ->andReturn([
                'success' => true,
                'ticket_id' => 12345,
                'ticket_number' => 'TN123456',
                'warnings' => ['Minor warning'],
            ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            // Initializing necessary state that would be populated by opening the modal
            ->set('ticketModalEventId', '1001')
            ->set('ticketModalProblem', [
                'eventid' => '1001',
                'host_name' => 'TestCompany swiss test01',
                'name' => 'TestCompany CPU Load',
                'objectid' => '3001',
                'hosts' => [['hostid' => '2001']],
            ])
            ->set('ticketOwnerId', '10')
            ->set('ticketQueue', 'TestCompany')
            ->set('ticketCustomerUser', 'TestCompanyClients')
            ->set('ticketTextTitle', 'Test Title')
            ->set('ticketTextArticleSubject', 'Test Subject')
            ->set('ticketTextArticleBody', 'Test Body')
            ->set('isTicketTextModalOpen', true)
            ->call('createZnunyTicket')
            ->assertSet('ticketValidationStatus', 'success')
            ->assertSet('isTicketTextModalOpen', false)
            ->assertNotified();
    }

    public function test_create_ticket_persists_identity_and_stores_local_record()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Mock the HTTP client instead of the service to test full local DB insertion
        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldReceive('validateTicketCreate')->andReturn([
            'valid' => true,
            'errors' => [],
            'warnings' => [],
        ]);
        $clientMock->shouldReceive('createTicket')->andReturn([
            'success' => true,
            'ticket_id' => 7777,
            'ticket_number' => 'TN777777',
            'warnings' => [],
        ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->set('ticketModalEventId', '1005')
            ->set('ticketModalProblem', [
                'eventid' => '1005',
                'host_name' => 'TestCompany swiss test05',
                'name' => 'TestCompany Memory Load',
                'objectid' => '3005',
                'hosts' => [['hostid' => '2005']],
            ])
            ->set('ticketOwnerId', '10')
            ->set('ticketQueue', 'TestCompany')
            ->set('ticketCustomerUser', 'TestCompanyClients')
            ->set('ticketTextTitle', 'Test Title')
            ->set('ticketTextArticleSubject', 'Test Subject')
            ->set('ticketTextArticleBody', 'Test Body')
            ->call('createZnunyTicket')
            ->assertSet('ticketValidationStatus', 'success');

        $ticket = ZabbixTicket::where('zabbix_event_id', '1005')->first();
        $this->assertNotNull($ticket);
        $this->assertEquals('2005', $ticket->zabbix_host_id);
        $this->assertEquals('3005', $ticket->zabbix_trigger_id);
        $this->assertEquals('TestCompany swiss test05', $ticket->zabbix_host_name);
        $this->assertEquals('TestCompany Memory Load', $ticket->zabbix_problem_name);
    }

    public function test_create_ticket_failure()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $serviceMock = $this->mock(ZnunyTicketCreationService::class);
        $serviceMock->shouldReceive('createTicketForProblem')
            ->once()
            ->andReturn([
                'success' => false,
                'errors' => ['CustomerUser not found.'],
                'warnings' => [],
            ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->set('ticketModalEventId', '1001')
            ->set('ticketModalProblem', [
                'eventid' => '1001',
                'host' => 'TestCompany swiss test01',
                'name' => 'TestCompany CPU Load',
            ])
            ->set('ticketOwnerId', '10')
            ->set('ticketQueue', 'TestCompany')
            ->set('ticketCustomerUser', 'InvalidClient')
            ->set('ticketTextTitle', 'Test Title')
            ->set('ticketTextArticleSubject', 'Test Subject')
            ->set('ticketTextArticleBody', 'Test Body')
            ->set('isTicketTextModalOpen', true)
            ->call('createZnunyTicket')
            ->assertSet('ticketValidationStatus', 'error')
            ->assertSet('ticketValidationErrors', ['CustomerUser not found.'])
            ->assertSet('isTicketTextModalOpen', true) // Modal stays open on error
            ->assertNotified();
    }

    public function test_create_ticket_missing_fields()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            // intentionally leaving fields empty (no event ID)
            ->call('createZnunyTicket')
            ->assertNotSet('ticketValidationStatus', 'validating') // should return early
            ->assertNotified(); // should have danger notification
    }

    public function test_create_ticket_duplicate()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $serviceMock = $this->mock(ZnunyTicketCreationService::class);
        $serviceMock->shouldReceive('createTicketForProblem')
            ->once()
            ->andReturn([
                'success' => false,
                'duplicate' => true,
                'ticket_id' => 99,
                'ticket_number' => 'TN99',
                'errors' => ['A ticket is already linked to this Zabbix event.'],
            ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->set('ticketModalEventId', '1001')
            ->set('ticketModalProblem', [
                'eventid' => '1001',
            ])
            ->set('ticketOwnerId', '10')
            ->set('ticketQueue', 'TestCompany')
            ->set('ticketCustomerUser', 'TestCompanyClients')
            ->set('ticketTextTitle', 'Test Title')
            ->set('ticketTextArticleBody', 'Test Body')
            ->call('createZnunyTicket')
            ->assertSet('ticketValidationStatus', 'error')
            ->assertSet('ticketValidationErrors', ['A ticket is already linked to this Zabbix event.'])
            ->assertNotified();
    }

    public function test_create_ticket_orphaned()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $serviceMock = $this->mock(ZnunyTicketCreationService::class);
        $serviceMock->shouldReceive('createTicketForProblem')
            ->once()
            ->andReturn([
                'success' => false,
                'orphaned' => true,
                'ticket_id' => 12345,
                'ticket_number' => 'TN123456',
                'errors' => ['Znuny ticket was created but linking to Zabbix problem failed locally.'],
            ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->set('ticketModalEventId', '1001')
            ->set('ticketModalProblem', [
                'eventid' => '1001',
            ])
            ->set('ticketOwnerId', '10')
            ->set('ticketQueue', 'TestCompany')
            ->set('ticketCustomerUser', 'TestCompanyClients')
            ->set('ticketTextTitle', 'Test Title')
            ->set('ticketTextArticleBody', 'Test Body')
            ->call('createZnunyTicket')
            ->assertSet('ticketValidationStatus', 'error')
            ->assertSet('ticketValidationErrors', ['Znuny ticket was created but linking to Zabbix problem failed locally.'])
            ->assertNotified();
    }

    public function test_opening_create_ticket_modal_initializes_generated_ticket_text()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Mock Agent
        Http::fake([
            '*example.invalid/api/Agent*' => Http::response(['Agents' => [['UserID' => 10, 'UserLogin' => 'agent1', 'UserFullname' => 'Agent One', 'ValidID' => 1]]], 200),
            '*example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            '*example.invalid/api/QueueByName*' => Http::response(['Queue' => ['QueueID' => 1, 'Name' => 'TestCompany', 'FullName' => 'TestCompany']], 200),
            '*example.invalid/api/Queue?*' => Http::response(['Queues' => [['QueueID' => 1, 'Name' => 'TestCompany', 'ValidID' => 1]]], 200),
            '*example.invalid/api/CustomerUser*' => Http::response(['CustomerUser' => ['UserLogin' => 'TestCompanyClients', 'UserCustomerID' => 'testcompany']], 200),
        ]);

        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->call('openCreateTicketModal', '1001');

        $component->assertSet('generatedTicketTextTitle', 'TestCompany CPU Load')
            ->assertSet('ticketTextTitle', 'TestCompany CPU Load')
            ->assertSet('ticketTextArticleSubject', 'Zabbix problem details');

        $this->assertStringContainsString('Problem: TestCompany CPU Load', $component->get('generatedTicketTextArticleBody'));
    }

    public function test_edit_ticket_text_modal_actions()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Http::fake([
            '*example.invalid/api/Agent*' => Http::response(['Agents' => [['UserID' => 10, 'UserLogin' => 'agent1', 'UserFullname' => 'Agent One', 'ValidID' => 1]]], 200),
            '*example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            '*example.invalid/api/QueueByName*' => Http::response(['Queue' => ['QueueID' => 1, 'Name' => 'TestCompany', 'FullName' => 'TestCompany']], 200),
            '*example.invalid/api/Queue?*' => Http::response(['Queues' => [['QueueID' => 1, 'Name' => 'TestCompany', 'ValidID' => 1]]], 200),
            '*example.invalid/api/CustomerUser*' => Http::response(['CustomerUser' => ['UserLogin' => 'TestCompanyClients', 'UserCustomerID' => 'testcompany']], 200),
        ]);

        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->call('openCreateTicketModal', '1001')
            ->call('openEditTicketTextModal')
            ->assertDispatched('open-modal', id: 'edit-ticket-text-modal')
            ->assertSet('isTicketTextModalOpen', true)
            ->set('ticketTextTitle', 'Edited Title')
            ->call('saveTicketText')
            ->assertSet('isTicketTextModalOpen', false)
            ->assertSet('ticketTextTitle', 'Edited Title')
            ->call('openEditTicketTextModal')
            ->call('resetTicketText')
            ->assertSet('ticketTextTitle', 'TestCompany CPU Load');
    }

    public function test_create_ticket_button_has_single_loading_indicator()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class);

        $html = $component->html();

        // The button label "Creating..." should only be associated with one spinner markup
        // since we removed the explicit <x-filament::loading-indicator>.
        // Here we just ensure we don't have `<x-filament::loading-indicator` inside the "Creating..." span block.
        // It's tricky to assert the absence of a generic string without parsing, but we can verify our fix is present:
        // We ensure "Creating..." exists and is not preceded by <svg class="fi-loading-indicator ..."> inside that block.

        // Assert that the explicit loading indicator string is NOT present alongside "Creating..."
        $this->assertEquals(0, substr_count($html, '<x-filament::loading-indicator class="w-4 h-4" /> Creating...'));

        // Since blade compiles components, we just assert the specific raw blade text is gone.
        // But more specifically, verify the span wrapper for Creating... is clean.
        $this->assertStringContainsString('<span wire:loading.flex wire:target="createZnunyTicket" class="items-center gap-2">', $html);
        $this->assertStringNotContainsString('</svg> Creating...', $html);
    }

    public function test_reopen_candidate_shows_reopen_action_and_warning_icon()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        ZabbixTicket::create([
            'zabbix_event_id' => '1001',
            'zabbix_host_name' => 'TestCompany',
            'zabbix_problem_name' => 'CPU Load',
            'znuny_ticket_id' => '1234',
            'znuny_ticket_number' => '2026000000000000',
            'manual_lifecycle_status' => 'reopen_candidate',
        ]);

        Setting::updateOrCreate(['key' => 'manual_ticket_reopen_note_template'], ['value' => 'Reopen me!', 'type' => 'string']);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class);

        $html = $component->html();
        $this->assertStringContainsString('Reopen', $html);
        $this->assertStringContainsString('Manual Reopen Candidate', $html);
        $this->assertStringContainsString('mountAction(\'reopenTicket\'', $html);
        $this->assertStringNotContainsString('Open Ticket', $html); // because it is a candidate
    }

    public function test_open_in_zabbix_button_rendered_when_template_set()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Setting::updateOrCreate(['key' => 'zabbix_problem_url_template'], ['value' => 'https://zabbix.test/?trigger={trigger_id}', 'type' => 'string']);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class);

        $html = $component->html();
        $this->assertStringContainsString('Open in Zabbix', $html);
        $this->assertStringContainsString('https://zabbix.test/?trigger=2001', $html);
        $this->assertStringContainsString('!bg-transparent', $html);
    }

    public function test_open_in_zabbix_button_hidden_when_template_empty()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Setting::updateOrCreate(['key' => 'zabbix_problem_url_template'], ['value' => '', 'type' => 'string']);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class);

        $html = $component->html();
        $this->assertStringNotContainsString('Open in Zabbix', $html);
    }

    public function test_expanded_detail_preserves_reopened_indicator_when_active()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt_old',
            'zabbix_host_id' => '2001',
            'zabbix_host_name' => 'Host 1',
            'zabbix_severity' => 3,
            'zabbix_problem_name' => 'Problem 1',
            'znuny_ticket_id' => 50005,
            'znuny_ticket_number' => '1005',
            'zabbix_trigger_id' => '2001',
            'manual_lifecycle_status' => 'active',
            'manual_reopened_at' => now()->subMinutes(5),
            'znuny_ticket_state_type' => 'open',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class);

        $html = $component->html();
        $this->assertStringContainsString('Manually reopened', $html);
        $this->assertStringContainsString('Reopened at:', $html);
    }

    public function test_refresh_from_zabbix_calls_poll_and_evaluate_lifecycle()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Artisan::shouldReceive('call')
            ->with('app:poll-zabbix-problems', ['--force' => true])
            ->once()
            ->andReturn(0);

        Artisan::shouldReceive('call')
            ->with('znuny:evaluate-manual-ticket-lifecycle')
            ->once()
            ->andReturn(0);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->call('refreshFromZabbix');
    }

    public function test_refresh_from_zabbix_skips_lifecycle_on_poll_failure()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Artisan::shouldReceive('call')
            ->with('app:poll-zabbix-problems', ['--force' => true])
            ->once()
            ->andReturn(1);

        Artisan::shouldReceive('call')
            ->with('znuny:evaluate-manual-ticket-lifecycle')
            ->never();

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->call('refreshFromZabbix');
    }

    public function test_icon_legend_is_rendered()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class);

        $html = $component->html();
        $this->assertStringContainsString('Icon legend', $html);
        $this->assertStringContainsString('Linked ticket', $html);
        $this->assertStringContainsString('Manual reopen candidate', $html);
        $this->assertStringContainsString('Manually reopened', $html);
        $this->assertStringContainsString('Flapping detected', $html);

        $this->assertStringContainsString('<table', $html);
        $this->assertStringNotContainsString('<ul class="grid', $html);
    }
}
