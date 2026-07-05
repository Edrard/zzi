<?php

namespace Tests\Feature;

use App\Filament\Pages\CurrentZabbixProblems;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\ZabbixTicket;
use App\Services\OwnerSuggestion\OwnerSuggestionSelector;
use App\Services\SettingsService;
use App\Services\Zabbix\ZabbixProblemCache;
use App\Services\Znuny\ZnunyAgentService;
use App\Services\Znuny\ZnunyAssignmentDependencyService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketArticleWriteService;
use App\Services\Znuny\ZnunyTicketCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

class CurrentZabbixProblemsTicketModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
            '*example.invalid/api/Queue/1/AssignableAgents*' => Http::response([
                'Agents' => [
                    ['UserID' => 10, 'UserLogin' => 'agent1', 'UserFullname' => 'Agent One'],
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

    public function test_admin_modal_filters_excluded_agents()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Setting::updateOrCreate(['key' => 'znuny_agent_exclude_logins'], ['value' => 'agent2', 'type' => 'string']);

        // Mock Agent
        Http::fake([
            '*example.invalid/api/Agent*' => Http::response([
                'Agents' => [
                    ['UserID' => 10, 'UserLogin' => 'agent1', 'UserFullname' => 'Agent One', 'ValidID' => 1],
                    ['UserID' => 11, 'UserLogin' => 'agent2', 'UserFullname' => 'Agent Two', 'ValidID' => 1],
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
            '*example.invalid/api/Queue/1/AssignableAgents*' => Http::response([
                'Agents' => [
                    ['UserID' => 10, 'UserLogin' => 'agent1', 'UserFullname' => 'Agent One'],
                    ['UserID' => 11, 'UserLogin' => 'agent2', 'UserFullname' => 'Agent Two'],
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
            ->assertSet('ticketModalEventId', '1001');

        $options = $component->get('ticketOwnerOptions');

        $this->assertArrayHasKey(10, $options);
        $this->assertArrayNotHasKey(11, $options);
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

        $dependencyMock = $this->mock(ZnunyAssignmentDependencyService::class);
        $dependencyMock->shouldReceive('isOwnerValidForQueue')->andReturn(true);
        $dependencyMock->shouldReceive('getOwnerOptionsForQueue')->andReturn(['10' => 'Agent One']);
        $dependencyMock->shouldReceive('getQueueOptionsForOwnerId')->andReturn(['TestCompany' => 'TestCompany']);

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
        $clientMock->shouldReceive('getCustomerUser')->andReturn(['found' => true, 'customer_id' => 'CUST_TEST']);
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

        $dependencyMock = $this->mock(ZnunyAssignmentDependencyService::class);
        $dependencyMock->shouldReceive('isOwnerValidForQueue')->andReturn(true);
        $dependencyMock->shouldReceive('getOwnerOptionsForQueue')->andReturn(['10' => 'Agent One']);
        $dependencyMock->shouldReceive('getQueueOptionsForOwnerId')->andReturn(['TestCompany' => 'TestCompany']);

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

        $dependencyMock = $this->mock(ZnunyAssignmentDependencyService::class);
        $dependencyMock->shouldReceive('isOwnerValidForQueue')->andReturn(true);
        $dependencyMock->shouldReceive('getOwnerOptionsForQueue')->andReturn(['10' => 'Agent One']);
        $dependencyMock->shouldReceive('getQueueOptionsForOwnerId')->andReturn(['TestCompany' => 'TestCompany']);

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

        $dependencyMock = $this->mock(ZnunyAssignmentDependencyService::class);
        $dependencyMock->shouldReceive('isOwnerValidForQueue')->andReturn(true);
        $dependencyMock->shouldReceive('getOwnerOptionsForQueue')->andReturn(['10' => 'Agent One']);
        $dependencyMock->shouldReceive('getQueueOptionsForOwnerId')->andReturn(['TestCompany' => 'TestCompany']);

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

        $dependencyMock = $this->mock(ZnunyAssignmentDependencyService::class);
        $dependencyMock->shouldReceive('isOwnerValidForQueue')->andReturn(true);
        $dependencyMock->shouldReceive('getOwnerOptionsForQueue')->andReturn(['10' => 'Agent One']);
        $dependencyMock->shouldReceive('getQueueOptionsForOwnerId')->andReturn(['TestCompany' => 'TestCompany']);

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
            '*example.invalid/api/Queue/1/AssignableAgents*' => Http::response(['Agents' => [['UserID' => 10, 'UserLogin' => 'agent1', 'UserFullname' => 'Agent One']]], 200),
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
            '*example.invalid/api/Queue/1/AssignableAgents*' => Http::response(['Agents' => [['UserID' => 10, 'UserLogin' => 'agent1', 'UserFullname' => 'Agent One']]], 200),
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
            ->with('app:poll-zabbix-problems', ['--force' => true, '--manual' => true])
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
            ->with('app:poll-zabbix-problems', ['--force' => true, '--manual' => true])
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

    public function test_ticket_details_button_renders_for_open_linked_ticket()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => '1001',
            'zabbix_host_id' => '2001',
            'zabbix_host_name' => 'TestCompany swiss test01',
            'zabbix_severity' => 4,
            'zabbix_trigger_id' => '2001',
            'zabbix_problem_name' => 'TestCompany CPU Load',
            'znuny_ticket_id' => 50001,
            'znuny_ticket_number' => '1001',
            'znuny_ticket_state_type' => 'open',
            'manual_lifecycle_status' => 'active',
        ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->assertSeeHtml('Ticket details')
            ->assertSeeHtml('zbx-ticket-details-button')
            ->assertSeeHtml("mountAction('viewTicket', { zabbix_ticket_id: {$ticket->id} })");
    }

    public function test_ticket_details_button_hidden_for_closed_ticket()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        ZabbixTicket::create([
            'zabbix_event_id' => '1001',
            'zabbix_host_id' => '2001',
            'zabbix_host_name' => 'TestCompany swiss test01',
            'zabbix_severity' => 4,
            'zabbix_trigger_id' => '2001',
            'zabbix_problem_name' => 'TestCompany CPU Load',
            'znuny_ticket_id' => 50001,
            'znuny_ticket_number' => '1001',
            'znuny_state_name' => 'closed successful',
            'znuny_ticket_state_type' => 'closed',
            'manual_lifecycle_status' => 'closed',
        ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->assertDontSeeHtml("mountAction('viewTicket'");
    }

    public function test_ticket_details_button_hidden_for_reopen_candidate()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        ZabbixTicket::create([
            'zabbix_event_id' => '1001',
            'zabbix_host_id' => '2001',
            'zabbix_host_name' => 'TestCompany swiss test01',
            'zabbix_severity' => 4,
            'zabbix_trigger_id' => '2001',
            'zabbix_problem_name' => 'TestCompany CPU Load',
            'znuny_ticket_id' => 50001,
            'znuny_ticket_number' => '1001',
            'znuny_state_name' => 'closed successful',
            'znuny_ticket_state_type' => 'closed',
            'manual_lifecycle_status' => 'reopen_candidate',
        ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->assertDontSeeHtml("mountAction('viewTicket'");
    }

    public function test_ticket_details_action_opens_modal_with_close_button_if_applicable()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => '1001',
            'zabbix_host_id' => '2001',
            'zabbix_host_name' => 'TestCompany swiss test01',
            'zabbix_severity' => 4,
            'zabbix_trigger_id' => '2001',
            'zabbix_problem_name' => 'TestCompany CPU Load',
            'znuny_ticket_id' => 50001,
            'znuny_ticket_number' => '1001',
            'znuny_ticket_state_type' => 'open',
            'manual_lifecycle_status' => 'active',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->assertActionExists('viewTicket')
            ->mountAction('viewTicket', ['zabbix_ticket_id' => $ticket->id])
            ->assertActionMounted('viewTicket');

        $action = $component->instance()->getMountedAction();

        $openSubmitAction = $action->getModalSubmitAction();
        $this->assertNull($openSubmitAction, 'Submit action should be disabled');

        $footerActions = $action->getExtraModalFooterActions();
        $this->assertArrayHasKey('open_ticket', $footerActions, 'open_ticket should be in extra footer actions');

        $openAction = $footerActions['open_ticket'];
        $this->assertEquals('Open Ticket', $openAction->getLabel());

        $attributes = $openAction->getExtraAttributes();
        $this->assertArrayHasKey('class', $attributes);
        $this->assertStringContainsString('zbx-open-ticket-footer-action', $attributes['class'], 'Open Ticket must have zbx-open-ticket-footer-action class to align right');
    }

    public function test_reopen_action_can_be_mounted_for_reopen_candidate()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => '1001',
            'zabbix_host_id' => '2001',
            'zabbix_host_name' => 'TestCompany swiss test01',
            'zabbix_severity' => 4,
            'zabbix_trigger_id' => '2001',
            'zabbix_problem_name' => 'TestCompany CPU Load',
            'znuny_ticket_id' => 50001,
            'znuny_ticket_number' => '1001',
            'znuny_ticket_state_type' => 'closed',
            'manual_lifecycle_status' => 'reopen_candidate',
        ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->assertActionExists('reopenTicket')
            ->mountAction('reopenTicket', ['zabbix_ticket_id' => $ticket->id])
            ->assertActionMounted('reopenTicket');
    }

    public function test_audit_logs_are_not_created_on_ui_render()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $initialCount = AuditLog::count();

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->assertSuccessful()
            ->call('$refresh');

        $this->assertDatabaseCount('audit_logs', $initialCount);
    }

    public function test_add_note_or_article_action_submits_note_and_invalidates_cache()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => '1001',
            'zabbix_host_id' => '2001',
            'zabbix_host_name' => 'TestCompany swiss test01',
            'zabbix_severity' => 4,
            'zabbix_trigger_id' => '2001',
            'zabbix_problem_name' => 'TestCompany CPU Load',
            'znuny_ticket_id' => 50001,
            'znuny_ticket_number' => '1001',
            'znuny_ticket_state_type' => 'open',
            'manual_lifecycle_status' => 'active',
        ]);

        $serviceMock = $this->mock(ZnunyTicketArticleWriteService::class);
        $serviceMock->shouldReceive('createTicketArticle')
            ->once()
            ->with('50001', 'Test Note Subject', 'Test Note Body', false)
            ->andReturn([
                'success' => true,
                'article_id' => 123,
                'ticket_id' => 50001,
            ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->mountAction('viewTicket', ['zabbix_ticket_id' => $ticket->id])
            ->mountAction('add_note_or_article', ['zabbix_ticket_id' => $ticket->id])
            ->setActionData([
                'subject' => 'Test Note Subject',
                'body' => 'Test Note Body',
            ])
            ->callMountedAction(['visible_for_customer' => false])
            ->assertHasNoActionErrors()
            ->assertNotified();
    }

    public function test_add_note_or_article_action_submits_article()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => '1001',
            'zabbix_host_id' => '2001',
            'zabbix_host_name' => 'TestCompany swiss test01',
            'zabbix_severity' => 4,
            'zabbix_trigger_id' => '2001',
            'zabbix_problem_name' => 'TestCompany CPU Load',
            'znuny_ticket_id' => 50001,
            'znuny_ticket_number' => '1001',
            'znuny_ticket_state_type' => 'open',
            'manual_lifecycle_status' => 'active',
        ]);

        $serviceMock = $this->mock(ZnunyTicketArticleWriteService::class);
        $serviceMock->shouldReceive('createTicketArticle')
            ->once()
            ->with('50001', 'Test Article Subject', 'Test Article Body', true)
            ->andReturn([
                'success' => true,
                'article_id' => 124,
                'ticket_id' => 50001,
            ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->mountAction('viewTicket', ['zabbix_ticket_id' => $ticket->id])
            ->mountAction('add_note_or_article', ['zabbix_ticket_id' => $ticket->id])
            ->setActionData([
                'subject' => 'Test Article Subject',
                'body' => 'Test Article Body',
            ])
            ->callMountedAction(['visible_for_customer' => true])
            ->assertHasNoActionErrors()
            ->assertNotified();
    }

    public function test_add_note_or_article_action_requires_subject_and_body()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => '1001',
            'zabbix_host_id' => '2001',
            'zabbix_host_name' => 'TestCompany swiss test01',
            'zabbix_severity' => 4,
            'zabbix_trigger_id' => '2001',
            'zabbix_problem_name' => 'TestCompany CPU Load',
            'znuny_ticket_id' => 50001,
            'znuny_ticket_number' => '1001',
            'znuny_ticket_state_type' => 'open',
            'manual_lifecycle_status' => 'active',
        ]);

        $serviceMock = $this->mock(ZnunyTicketArticleWriteService::class);
        $serviceMock->shouldReceive('createTicketArticle')->never();

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->mountAction('viewTicket', ['zabbix_ticket_id' => $ticket->id])
            ->mountAction('add_note_or_article', ['zabbix_ticket_id' => $ticket->id])
            ->setActionData([
                'subject' => '', // missing
                'body' => '',    // missing
            ])
            ->callMountedAction(['visible_for_customer' => false])
            ->assertHasActionErrors(['subject' => 'required', 'body' => 'required']);
    }

    public function test_current_problems_modal_queue_changes_owner_options_restricted()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        Http::fake([
            '*example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            '*example.invalid/api/QueueByName*' => Http::response([
                'Queue' => ['QueueID' => 1, 'Name' => 'TestQueue', 'ValidID' => 1],
            ], 200),
            '*example.invalid/api/Queue/1/AssignableAgents*' => Http::response([
                'Agents' => [
                    ['UserID' => 10, 'UserLogin' => 'agent1', 'UserFullname' => 'Agent One'],
                ],
            ], 200),
        ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->set('ticketQueue', 'TestQueue')
            ->assertSet('ticketOwnerOptions.10', 'Agent One <agent1>');
    }

    public function test_current_problems_modal_queue_changes_clears_invalid_owner()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        Http::fake([
            '*example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            '*example.invalid/api/QueueByName*' => Http::response([
                'Queue' => ['QueueID' => 1, 'Name' => 'TestQueue', 'ValidID' => 1],
            ], 200),
            '*example.invalid/api/Queue/1/AssignableAgents*' => Http::response([
                'Agents' => [
                    ['UserID' => 20, 'UserLogin' => 'agent2', 'UserFullname' => 'Agent Two'],
                ],
            ], 200),
            '*example.invalid/api/Agent/10/AssignableQueues*' => Http::response([
                'Queues' => [
                    ['QueueID' => 5, 'Name' => 'ValidQueue', 'FullName' => 'ValidQueue'],
                ],
            ], 200),
        ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->set('ticketOwnerId', '10') // Invalid for TestQueue
            ->set('ticketQueue', 'TestQueue')
            ->assertSet('ticketOwnerId', null)
            ->assertNotified('Owner Cleared');
    }

    public function test_current_problems_modal_owner_changes_queue_options_restricted()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        Http::fake([
            '*example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            '*example.invalid/api/Agent/10/AssignableQueues*' => Http::response([
                'Queues' => [
                    ['QueueID' => 5, 'Name' => 'ValidQueue', 'FullName' => 'ValidQueue'],
                ],
            ], 200),
        ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->set('ticketOwnerId', '10')
            ->assertSet('ticketQueueOptions.ValidQueue', 'ValidQueue');
    }

    public function test_current_problems_modal_owner_changes_clears_invalid_queue()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        Http::fake([
            '*example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            '*example.invalid/api/Agent/10/AssignableQueues*' => Http::response([
                'Queues' => [
                    ['QueueID' => 5, 'Name' => 'ValidQueue', 'FullName' => 'ValidQueue'],
                ],
            ], 200),
            '*example.invalid/api/QueueByName/InvalidQueue*' => Http::response([], 200),
            '*example.invalid/api/QueueByName*' => Http::response([], 200),
        ]);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->set('ticketQueue', 'InvalidQueue') // Invalid for agent 10
            ->set('ticketOwnerId', '10')
            ->assertSet('ticketQueue', null)
            ->assertNotified('Queue Cleared');
    }

    public function test_current_problems_modal_view_uses_live_bindings()
    {
        $viewPath = resource_path('views/filament/pages/current-zabbix-problems.blade.php');
        $this->assertFileExists($viewPath);

        $content = file_get_contents($viewPath);

        $this->assertStringContainsString('wire:model.live="ticketOwnerId"', $content, 'Ticket owner select must use live binding to trigger dependency checks.');
        $this->assertStringContainsString('wire:model.live="ticketQueue"', $content, 'Ticket queue select must use live binding to trigger dependency checks.');
        $this->assertStringNotContainsString('wire:model="ticketOwnerId"', $content, 'Ticket owner select must not use deferred binding.');
        $this->assertStringNotContainsString('wire:model="ticketQueue"', $content, 'Ticket queue select must not use deferred binding.');
    }

    protected function setupMocksForSuggestionTests()
    {
        Http::fake([
            '*example.invalid/api/Agent*' => Http::response([
                'Agents' => [
                    ['UserID' => 10, 'UserLogin' => 'agent1', 'UserFullname' => 'Agent One', 'ValidID' => 1],
                    ['UserID' => 20, 'UserLogin' => 'agent2', 'UserFullname' => 'Agent Two', 'ValidID' => 1],
                    ['UserID' => 30, 'UserLogin' => 'agent3', 'UserFullname' => 'Agent Three', 'ValidID' => 1],
                ],
            ], 200),
            '*example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            '*example.invalid/api/QueueByName*Queue%20B*' => Http::response([
                'Queue' => ['QueueID' => 2, 'Name' => 'Queue B', 'FullName' => 'Queue B'],
            ], 200),
            '*example.invalid/api/QueueByName*Queue+B*' => Http::response([
                'Queue' => ['QueueID' => 2, 'Name' => 'Queue B', 'FullName' => 'Queue B'],
            ], 200),
            '*example.invalid/api/QueueByName*' => Http::response([
                'Queue' => ['QueueID' => 1, 'Name' => 'TestCompany', 'FullName' => 'TestCompany'],
            ], 200),
            '*example.invalid/api/Queue/1/AssignableAgents*' => Http::response([
                'Agents' => [
                    ['UserID' => 10, 'UserLogin' => 'agent1', 'UserFullname' => 'Agent One'],
                    ['UserID' => 20, 'UserLogin' => 'agent2', 'UserFullname' => 'Agent Two'],
                ],
            ], 200),
            '*example.invalid/api/Queue/2/AssignableAgents*' => Http::response([
                'Agents' => [
                    ['UserID' => 10, 'UserLogin' => 'agent1', 'UserFullname' => 'Agent One'],
                    ['UserID' => 30, 'UserLogin' => 'agent3', 'UserFullname' => 'Agent Three'],
                ],
            ], 200),
            '*example.invalid/api/Queue?*' => Http::response([
                'Queues' => [
                    ['QueueID' => 1, 'Name' => 'TestCompany', 'ValidID' => 1],
                    ['QueueID' => 2, 'Name' => 'Queue B', 'ValidID' => 1],
                ],
            ], 200),
            '*example.invalid/api/CustomerUser*' => Http::response([
                'CustomerUser' => ['UserLogin' => 'TestCompanyClients', 'UserCustomerID' => 'testcompany'],
            ], 200),
            '*' => Http::response([], 200),
        ]);

        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);
    }

    public function test_suggested_owner_is_preselected_on_modal_open()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setupMocksForSuggestionTests();

        $selectorMock = $this->mock(OwnerSuggestionSelector::class);
        $selectorMock->shouldReceive('suggest')
            ->once()
            ->andReturn([
                'owner_id' => '20',
                'owner_login' => 'agent2',
                'queue_name' => 'TestCompany',
                'normalized_problem_key' => 'cpu load',
                'matched_normalized_problem_key' => 'cpu load',
                'similarity' => 1.0,
                'score' => 100,
                'sample_count' => 5,
                'recent_count' => 5,
                'old_count' => 0,
                'last_seen_at' => null,
            ]);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->call('openCreateTicketModal', '1001');

        $component->assertSet('ticketOwnerId', '20')
            ->assertSet('suggestedOwnerId', '20')
            ->assertSet('ownerSuggestionApplied', true);

        $options = $component->get('ticketOwnerOptions');
        $this->assertEquals(20, array_keys($options)[0]);
    }

    public function test_suggested_owner_appears_first_in_dropdown()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setupMocksForSuggestionTests();

        $selectorMock = $this->mock(OwnerSuggestionSelector::class);
        $selectorMock->shouldReceive('suggest')
            ->andReturn([
                'owner_id' => '20',
                'owner_login' => 'agent2',
                'queue_name' => 'TestCompany',
                'normalized_problem_key' => 'cpu load',
                'matched_normalized_problem_key' => 'cpu load',
                'similarity' => 1.0,
                'score' => 100,
                'sample_count' => 5,
                'recent_count' => 5,
                'old_count' => 0,
                'last_seen_at' => null,
            ]);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->call('openCreateTicketModal', '1001');

        $options = $component->get('ticketOwnerOptions');
        $this->assertEquals(20, array_keys($options)[0]);
        $this->assertArrayHasKey(10, $options);
    }

    public function test_suggestion_is_ignored_if_owner_is_not_assignable()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setupMocksForSuggestionTests();

        $selectorMock = $this->mock(OwnerSuggestionSelector::class);
        $selectorMock->shouldReceive('suggest')
            ->andReturn([
                'owner_id' => '99',
                'owner_login' => 'unknown',
                'queue_name' => 'TestCompany',
                'normalized_problem_key' => 'cpu load',
                'matched_normalized_problem_key' => 'cpu load',
                'similarity' => 1.0,
                'score' => 100,
                'sample_count' => 5,
                'recent_count' => 5,
                'old_count' => 0,
                'last_seen_at' => null,
            ]);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->call('openCreateTicketModal', '1001');

        $component->assertSet('ticketOwnerId', null)
            ->assertSet('suggestedOwnerId', null)
            ->assertSet('ownerSuggestionApplied', false);
    }

    public function test_manual_owner_selection_is_not_overridden()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setupMocksForSuggestionTests();

        $selectorMock = $this->mock(OwnerSuggestionSelector::class);
        $selectorMock->shouldReceive('suggest')
            ->andReturn([
                'owner_id' => '20',
                'owner_login' => 'agent2',
                'queue_name' => 'TestCompany',
                'normalized_problem_key' => 'cpu load',
                'matched_normalized_problem_key' => 'cpu load',
                'similarity' => 1.0,
                'score' => 100,
                'sample_count' => 5,
                'recent_count' => 5,
                'old_count' => 0,
                'last_seen_at' => null,
            ]);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->call('openCreateTicketModal', '1001')
            ->assertSet('ticketOwnerId', '20');

        $component->set('ticketOwnerId', '10');
        $component->assertSet('ownerManuallyChanged', true);

        $component->set('ticketQueue', 'TestCompany');

        $component->assertSet('ticketOwnerId', '10')
            ->assertSet('ownerManuallyChanged', true);
    }

    public function test_queue_change_can_update_suggestion_when_previous_owner_was_auto_selected()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setupMocksForSuggestionTests();

        $selectorMock = $this->mock(OwnerSuggestionSelector::class);
        $selectorMock->shouldReceive('suggest')
            ->withArgs(function ($name, $queue, $ids, $logins) {
                return $queue === 'TestCompany';
            })
            ->andReturn([
                'owner_id' => '20',
                'owner_login' => 'agent2',
                'queue_name' => 'TestCompany',
                'normalized_problem_key' => 'cpu load',
                'matched_normalized_problem_key' => 'cpu load',
                'similarity' => 1.0,
                'score' => 100,
                'sample_count' => 5,
                'recent_count' => 5,
                'old_count' => 0,
                'last_seen_at' => null,
            ]);

        $selectorMock->shouldReceive('suggest')
            ->withArgs(function ($name, $queue, $ids, $logins) {
                return $queue === 'Queue B';
            })
            ->andReturn([
                'owner_id' => '30',
                'owner_login' => 'agent3',
                'queue_name' => 'Queue B',
                'normalized_problem_key' => 'cpu load',
                'matched_normalized_problem_key' => 'cpu load',
                'similarity' => 1.0,
                'score' => 100,
                'sample_count' => 5,
                'recent_count' => 5,
                'old_count' => 0,
                'last_seen_at' => null,
            ]);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->call('openCreateTicketModal', '1001')
            ->assertSet('ticketOwnerId', '20');

        $component->set('ticketQueue', 'Queue B');

        $component->assertSet('ticketOwnerId', '30')
            ->assertSet('ownerManuallyChanged', false);
    }

    public function test_selector_failure_does_not_break_modal()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setupMocksForSuggestionTests();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'Failed to apply owner suggestion: Database failure');
            });

        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $selectorMock = $this->mock(OwnerSuggestionSelector::class);
        $selectorMock->shouldReceive('suggest')
            ->andThrow(new \Exception('Database failure'));

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->call('openCreateTicketModal', '1001');

        $component->assertSet('ticketModalEventId', '1001')
            ->assertSet('ticketOwnerId', null)
            ->assertSet('ticketValidationErrors', [])
            ->assertSet('ticketValidationWarnings', []);
    }

    public function test_selector_receives_both_owner_ids_and_logins()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setupMocksForSuggestionTests();

        $selectorMock = $this->mock(OwnerSuggestionSelector::class);
        $selectorMock->shouldReceive('suggest')
            ->once()
            ->withArgs(function ($name, $queue, $ids, $logins) {
                // For 'TestCompany' queue, assignable agents are 10 (agent1) and 20 (agent2)
                return in_array(10, $ids) && in_array(20, $ids) &&
                       in_array('agent1', $logins) && in_array('agent2', $logins);
            })
            ->andReturn(null);

        Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->call('openCreateTicketModal', '1001');
    }

    public function test_suggestion_can_match_by_owner_login_when_needed()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setupMocksForSuggestionTests();

        $selectorMock = $this->mock(OwnerSuggestionSelector::class);
        $selectorMock->shouldReceive('suggest')
            ->once()
            ->andReturn([
                'owner_id' => null, // Intentionally missing
                'owner_login' => 'agent2',
                'queue_name' => 'TestCompany',
                'normalized_problem_key' => 'cpu load',
                'matched_normalized_problem_key' => 'cpu load',
                'similarity' => 1.0,
                'score' => 100,
                'sample_count' => 5,
                'recent_count' => 5,
                'old_count' => 0,
                'last_seen_at' => null,
            ]);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->call('openCreateTicketModal', '1001');

        $component->assertSet('ticketOwnerId', '20')
            ->assertSet('suggestedOwnerId', '20')
            ->assertSet('ownerSuggestionApplied', true);
    }

    public function test_suggestion_uses_login_fallback_if_owner_id_is_stale()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setupMocksForSuggestionTests();

        $selectorMock = $this->mock(OwnerSuggestionSelector::class);
        $selectorMock->shouldReceive('suggest')
            ->once()
            ->andReturn([
                'owner_id' => 'stale_owner_id', // Intentionally stale
                'owner_login' => 'agent2',
                'queue_name' => 'TestCompany',
                'normalized_problem_key' => 'cpu load',
                'matched_normalized_problem_key' => 'cpu load',
                'similarity' => 1.0,
                'score' => 100,
                'sample_count' => 5,
                'recent_count' => 5,
                'old_count' => 0,
                'last_seen_at' => null,
            ]);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->call('openCreateTicketModal', '1001');

        $component->assertSet('ticketOwnerId', '20')
            ->assertSet('suggestedOwnerId', '20')
            ->assertSet('ownerSuggestionApplied', true);

        $options = $component->get('ticketOwnerOptions');
        $this->assertArrayNotHasKey('stale_owner_id', $options);
    }

    public function test_successful_queue_mapping_goes_to_notes()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setupMocksForSuggestionTests();

        $cache = app(ZabbixProblemCache::class);
        $problems = $cache->all();
        $problems[0]['host_name'] = 'SocialProduct server';
        $cache->putMany($problems, 3600);

        Setting::updateOrCreate(['key' => 'znuny_queue_host_mappings'], ['value' => json_encode([
            ['host_prefix' => 'SocialProduct', 'queue_name' => 'TestCompany'],
        ]), 'type' => 'json']);

        Http::fake([
            '*example.invalid/api/Queue?*' => Http::response([
                'Queues' => [
                    ['QueueID' => 99, 'Name' => 'Mapped Queue', 'ValidID' => 1],
                ],
            ], 200),
            '*example.invalid/api/Queue/99/AssignableAgents*' => Http::response([
                'Agents' => [
                    ['UserID' => 10, 'UserLogin' => 'agent1', 'UserFullname' => 'Agent One'],
                ],
            ], 200),
        ]);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->call('openCreateTicketModal', '1001');

        $notes = $component->get('ticketDefaultNotes');
        $warnings = $component->get('ticketDefaultWarnings');

        $this->assertContains('Queue resolved by prefix: SocialProduct → TestCompany', $notes);
        $this->assertNotContains('Queue mapping matched prefix: SocialProduct → TestCompany', $warnings);
        $this->assertNotContains('Queue resolved by prefix: SocialProduct → TestCompany', $warnings);
    }

    public function test_successful_queue_mapping_note_is_rendered_as_neutral_text()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setupMocksForSuggestionTests();

        $cache = app(ZabbixProblemCache::class);
        $problems = $cache->all();
        $problems[0]['host_name'] = 'SocialProduct server';
        $cache->putMany($problems, 3600);

        Setting::updateOrCreate(['key' => 'znuny_queue_host_mappings'], ['value' => json_encode([
            ['host_prefix' => 'SocialProduct', 'queue_name' => 'TestCompany'],
        ]), 'type' => 'json']);

        Http::fake([
            '*example.invalid/api/Queue?*' => Http::response([
                'Queues' => [
                    ['QueueID' => 99, 'Name' => 'Mapped Queue', 'ValidID' => 1],
                ],
            ], 200),
            '*example.invalid/api/Queue/99/AssignableAgents*' => Http::response([
                'Agents' => [
                    ['UserID' => 10, 'UserLogin' => 'agent1', 'UserFullname' => 'Agent One'],
                ],
            ], 200),
        ]);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->call('openCreateTicketModal', '1001');

        $notes = $component->get('ticketDefaultNotes');
        if (empty($notes)) {
            echo 'NOTES ARE EMPTY\n';
        } else {
            echo 'NOTES: '.$notes[0].'\n';
        }
        $component->assertSee('Queue resolved');
    }

    public function test_failed_queue_mapping_remains_as_warning()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setupMocksForSuggestionTests();

        $cache = app(ZabbixProblemCache::class);
        $problems = $cache->all();
        $problems[0]['host_name'] = 'SocialProduct server';
        $cache->putMany($problems, 3600);

        Setting::updateOrCreate(['key' => 'znuny_queue_host_mappings'], ['value' => json_encode([
            ['host_prefix' => 'SocialProduct', 'queue_name' => 'Missing Queue'],
        ]), 'type' => 'json']);

        Http::fake([
            '*example.invalid/api/Queue?*' => Http::response([
                'Queues' => [],
            ], 200),
        ]);

        $component = Livewire::actingAs($admin)
            ->test(CurrentZabbixProblems::class)
            ->call('openCreateTicketModal', '1001');

        $warnings = $component->get('ticketDefaultWarnings');
        $notes = $component->get('ticketDefaultNotes');

        $this->assertContains('Mapped queue not found in Znuny: Missing Queue', $warnings);
        $this->assertEmpty($notes);

        $component->assertSee('Mapped queue not found in Znuny: Missing Queue');
        $component->assertDontSee('Queue resolved by prefix');
    }
}
