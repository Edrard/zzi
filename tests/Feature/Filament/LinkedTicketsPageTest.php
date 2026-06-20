<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ZabbixTickets\Pages\ListZabbixTickets;
use App\Models\Setting;
use App\Models\User;
use App\Models\ZabbixTicket;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyLinkedTicketSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LinkedTicketsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_tickets_page_renders_without_error()
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        ZabbixTicket::create([
            'zabbix_event_id' => '12345',
            'zabbix_trigger_id' => '67890',
            'zabbix_host_id' => '111',
            'zabbix_host_name' => 'test-host',
            'zabbix_problem_name' => 'Test Problem',
            'zabbix_severity' => 3,
            'zabbix_started_at' => now(),
            'znuny_ticket_id' => 999,
            'znuny_ticket_number' => '202611223344',
            'znuny_state_name' => 'open',
            'znuny_ticket_state_type' => 'open',
            'creation_source' => 'manual',
        ]);

        Livewire::test(ListZabbixTickets::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(ZabbixTicket::all());
    }

    public function test_linked_tickets_main_table_has_expected_columns_only()
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test(ListZabbixTickets::class)
            ->assertCanRenderTableColumn('zabbix_host_name')
            ->assertCanRenderTableColumn('zabbix_problem_name')
            ->assertCanRenderTableColumn('znuny_state_name')
            ->assertCanRenderTableColumn('created_at')
            ->assertTableColumnDoesNotExist('znuny_ticket_number')
            ->assertTableColumnDoesNotExist('znuny_queue_name')
            ->assertTableColumnDoesNotExist('znuny_owner_name')
            ->assertTableColumnDoesNotExist('zabbix_severity');
    }

    public function test_linked_tickets_view_details_action_opens_slideover_without_crashing()
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => '12345',
            'zabbix_trigger_id' => '67890',
            'zabbix_host_id' => '111',
            'zabbix_host_name' => 'test-host',
            'zabbix_problem_name' => 'Test Problem',
            'zabbix_severity' => 3,
            'zabbix_started_at' => now(),
            'znuny_ticket_id' => 999,
            'znuny_ticket_number' => '202611223344',
            'znuny_queue_name' => 'Test Queue',
            'znuny_owner_name' => 'Test Owner',
            'znuny_priority' => '3 normal',
            'znuny_state_name' => 'open',
            'znuny_ticket_state_type' => 'open',
            'creation_source' => 'manual',
            'znuny_ticket_last_checked_at' => now(),
            'znuny_ticket_last_synced_at' => now(),
            'znuny_ticket_sync_error' => 'Some sync error',
        ]);

        Livewire::test(ListZabbixTickets::class)
            ->mountTableAction('view', $ticket)
            ->assertSuccessful();

    }

    public function test_linked_tickets_page_has_sync_action()
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test(ListZabbixTickets::class)
            ->assertActionExists('sync_tickets')
            ->assertActionDoesNotExist('refresh')
            ->assertSuccessful();
    }

    public function test_linked_tickets_page_polling_updates_local_db_state_without_sync()
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => '12345',
            'zabbix_trigger_id' => '67890',
            'zabbix_host_id' => '111',
            'zabbix_host_name' => 'test-host',
            'zabbix_problem_name' => 'Test Problem',
            'zabbix_severity' => 3,
            'zabbix_started_at' => now(),
            'znuny_ticket_id' => 999,
            'znuny_ticket_number' => '202611223344',
            'znuny_state_name' => 'open',
            'znuny_ticket_state_type' => 'open',
            'creation_source' => 'manual',
        ]);

        $mockSyncService = \Mockery::mock(ZnunyLinkedTicketSyncService::class);
        $mockSyncService->shouldNotReceive('sync');
        $this->app->instance(ZnunyLinkedTicketSyncService::class, $mockSyncService);

        $component = Livewire::test(ListZabbixTickets::class)
            ->assertCanSeeTableRecords([$ticket]);

        $this->assertEquals('open', $ticket->znuny_state_name);

        $ticket->update([
            'znuny_state_name' => 'closed unsuccessful',
            'znuny_ticket_state_type' => 'closed',
        ]);

        $component->call('$refresh')
            ->assertCanSeeTableRecords([$ticket]);

        $this->assertEquals('closed unsuccessful', $ticket->fresh()->znuny_state_name);
    }

    public function test_linked_tickets_open_ticket_action_has_url()
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => '12345',
            'zabbix_trigger_id' => '67890',
            'zabbix_host_id' => '111',
            'zabbix_host_name' => 'test-host',
            'zabbix_problem_name' => 'Test Problem',
            'zabbix_severity' => 3,
            'zabbix_started_at' => now(),
            'znuny_ticket_id' => 999,
            'znuny_ticket_number' => '202611223344',
            'creation_source' => 'manual',
        ]);

        $url = app(ZnunyClient::class)->ticketUrl(999);

        Livewire::test(ListZabbixTickets::class)
            ->assertTableActionHasUrl('open_ticket', $url, $ticket);
    }

    public function test_linked_tickets_resolution_context_badges_in_table()
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $ticket1 = ZabbixTicket::create([
            'zabbix_event_id' => '1', 'zabbix_trigger_id' => '1', 'zabbix_host_id' => '1', 'zabbix_host_name' => 'host1', 'zabbix_problem_name' => 'prob1', 'zabbix_severity' => 1, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 1, 'znuny_ticket_number' => '1', 'creation_source' => 'manual',
            'manual_lifecycle_status' => 'close_candidate', 'zabbix_problem_is_active' => false, 'znuny_ticket_state_type' => 'open',
        ]);

        $ticket2 = ZabbixTicket::create([
            'zabbix_event_id' => '2', 'zabbix_trigger_id' => '2', 'zabbix_host_id' => '2', 'zabbix_host_name' => 'host2', 'zabbix_problem_name' => 'prob2', 'zabbix_severity' => 2, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 2, 'znuny_ticket_number' => '2', 'creation_source' => 'manual',
            'manual_lifecycle_status' => 'active', 'zabbix_problem_is_active' => false, 'znuny_ticket_state_type' => 'open',
        ]);

        $ticket3 = ZabbixTicket::create([
            'zabbix_event_id' => '3', 'zabbix_trigger_id' => '3', 'zabbix_host_id' => '3', 'zabbix_host_name' => 'host3', 'zabbix_problem_name' => 'prob3', 'zabbix_severity' => 3, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 3, 'znuny_ticket_number' => '3', 'creation_source' => 'manual',
            'manual_lifecycle_status' => 'active', 'zabbix_problem_is_active' => true, 'znuny_ticket_state_type' => 'open',
        ]);

        $ticket4 = ZabbixTicket::create([
            'zabbix_event_id' => '4', 'zabbix_trigger_id' => '4', 'zabbix_host_id' => '4', 'zabbix_host_name' => 'host4', 'zabbix_problem_name' => 'prob4', 'zabbix_severity' => 4, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 4, 'znuny_ticket_number' => '4', 'creation_source' => 'manual',
            'manual_lifecycle_status' => 'close_candidate', 'zabbix_problem_is_active' => false, 'znuny_ticket_state_type' => 'closed',
        ]);

        Livewire::test(ListZabbixTickets::class)
            ->assertCanSeeTableRecords([$ticket1, $ticket2, $ticket3, $ticket4])
            ->assertSee('Ready to close')
            ->assertSee('Problem resolved');
    }

    public function test_linked_tickets_manual_close_action_visibility()
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $ticketOpen = ZabbixTicket::create([
            'zabbix_event_id' => '1', 'zabbix_trigger_id' => '1', 'zabbix_host_id' => '1', 'zabbix_host_name' => 'host1', 'zabbix_problem_name' => 'prob1', 'zabbix_severity' => 1, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 1, 'znuny_ticket_number' => '1', 'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open', 'znuny_state_name' => 'open',
        ]);

        $ticketClosed = ZabbixTicket::create([
            'zabbix_event_id' => '2', 'zabbix_trigger_id' => '2', 'zabbix_host_id' => '2', 'zabbix_host_name' => 'host2', 'zabbix_problem_name' => 'prob2', 'zabbix_severity' => 2, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 2, 'znuny_ticket_number' => '2', 'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'closed', 'znuny_state_name' => 'closed successful',
        ]);

        Livewire::test(ListZabbixTickets::class)
            ->assertTableActionVisible('manual_close_ticket', $ticketOpen)
            ->assertTableActionHidden('manual_close_ticket', $ticketClosed);
    }

    public function test_linked_tickets_manual_close_action_success()
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => '1', 'zabbix_trigger_id' => '1', 'zabbix_host_id' => '1', 'zabbix_host_name' => 'host1', 'zabbix_problem_name' => 'prob1', 'zabbix_severity' => 1, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 1, 'znuny_ticket_number' => '1000', 'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open', 'znuny_state_name' => 'open', 'manual_lifecycle_status' => 'active',
        ]);

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldReceive('ticketUrl')->andReturn('http://example.com/ticket/1');
        $clientMock->shouldReceive('closeTicket')
            ->once()
            ->with(1, \Mockery::on(function ($payload) {
                return $payload['Kind'] === 'internal_note' && $payload['Reason'] === 'Test close';
            }))
            ->andReturn(['success' => true]);

        $clientMock->shouldReceive('getTicket')
            ->once()
            ->with(1)
            ->andReturn(['StateType' => 'closed', 'State' => 'closed successful']);

        Livewire::test(ListZabbixTickets::class)
            ->callTableAction('manual_close_ticket', $ticket, data: ['reason' => 'Test close'])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Ticket Closed');

        $ticket->refresh();
        $this->assertEquals('closed', $ticket->znuny_ticket_state_type);
        $this->assertEquals('closed successful', $ticket->znuny_state_name);
        $this->assertEquals('closed', $ticket->manual_lifecycle_status);
    }

    public function test_linked_tickets_manual_close_action_failure()
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => '1', 'zabbix_trigger_id' => '1', 'zabbix_host_id' => '1', 'zabbix_host_name' => 'host1', 'zabbix_problem_name' => 'prob1', 'zabbix_severity' => 1, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 1, 'znuny_ticket_number' => '1000', 'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open', 'znuny_state_name' => 'open', 'manual_lifecycle_status' => 'active',
        ]);

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldReceive('ticketUrl')->andReturn('http://example.com/ticket/1');
        $clientMock->shouldReceive('closeTicket')
            ->once()
            ->andReturn(['success' => false, 'errors' => ['API error']]);

        Livewire::test(ListZabbixTickets::class)
            ->callTableAction('manual_close_ticket', $ticket, data: ['reason' => 'Test close'])
            ->assertNotified();

        $ticket->refresh();
        $this->assertEquals('open', $ticket->znuny_ticket_state_type);
        $this->assertEquals('open', $ticket->znuny_state_name);
        $this->assertEquals('active', $ticket->manual_lifecycle_status);
    }
}
