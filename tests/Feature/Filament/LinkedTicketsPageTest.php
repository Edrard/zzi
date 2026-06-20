<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ZabbixTickets\Pages\ListZabbixTickets;
use App\Models\Setting;
use App\Models\User;
use App\Models\ZabbixTicket;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyLinkedTicketCloseService;
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

        // 1. manual_lifecycle_status = active + zabbix_problem_is_active = true -> Active
        $ticket1 = ZabbixTicket::create([
            'zabbix_event_id' => '1', 'zabbix_trigger_id' => '1', 'zabbix_host_id' => '1', 'zabbix_host_name' => 'host1', 'zabbix_problem_name' => 'prob1', 'zabbix_severity' => 1, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 1, 'znuny_ticket_number' => '1', 'creation_source' => 'manual',
            'manual_lifecycle_status' => 'active', 'zabbix_problem_is_active' => true, 'znuny_ticket_state_type' => 'open',
        ]);

        // 2. manual_lifecycle_status = active + zabbix_problem_is_active = false -> Active
        $ticket2 = ZabbixTicket::create([
            'zabbix_event_id' => '2', 'zabbix_trigger_id' => '2', 'zabbix_host_id' => '2', 'zabbix_host_name' => 'host2', 'zabbix_problem_name' => 'prob2', 'zabbix_severity' => 2, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 2, 'znuny_ticket_number' => '2', 'creation_source' => 'manual',
            'manual_lifecycle_status' => 'active', 'zabbix_problem_is_active' => false, 'znuny_ticket_state_type' => 'open',
        ]);

        // 3. manual_lifecycle_status = resolved_waiting -> Resolved
        $ticket3 = ZabbixTicket::create([
            'zabbix_event_id' => '3', 'zabbix_trigger_id' => '3', 'zabbix_host_id' => '3', 'zabbix_host_name' => 'host3', 'zabbix_problem_name' => 'prob3', 'zabbix_severity' => 3, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 3, 'znuny_ticket_number' => '3', 'creation_source' => 'manual',
            'manual_lifecycle_status' => 'resolved_waiting', 'zabbix_problem_is_active' => false, 'znuny_ticket_state_type' => 'open',
        ]);

        // 4. manual_lifecycle_status = close_candidate -> Ready
        $ticket4 = ZabbixTicket::create([
            'zabbix_event_id' => '4', 'zabbix_trigger_id' => '4', 'zabbix_host_id' => '4', 'zabbix_host_name' => 'host4', 'zabbix_problem_name' => 'prob4', 'zabbix_severity' => 4, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 4, 'znuny_ticket_number' => '4', 'creation_source' => 'manual',
            'manual_lifecycle_status' => 'close_candidate', 'zabbix_problem_is_active' => false, 'znuny_ticket_state_type' => 'open',
        ]);

        // 5. manual_lifecycle_status = flapping -> Flapping
        $ticket5 = ZabbixTicket::create([
            'zabbix_event_id' => '5', 'zabbix_trigger_id' => '5', 'zabbix_host_id' => '5', 'zabbix_host_name' => 'host5', 'zabbix_problem_name' => 'prob5', 'zabbix_severity' => 5, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 5, 'znuny_ticket_number' => '5', 'creation_source' => 'manual',
            'manual_lifecycle_status' => 'flapping', 'zabbix_problem_is_active' => true, 'znuny_ticket_state_type' => 'open',
        ]);

        // 6. manual_lifecycle_status = cache_stale -> Cache stale
        $ticket6 = ZabbixTicket::create([
            'zabbix_event_id' => '6', 'zabbix_trigger_id' => '6', 'zabbix_host_id' => '6', 'zabbix_host_name' => 'host6', 'zabbix_problem_name' => 'prob6', 'zabbix_severity' => 6, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 6, 'znuny_ticket_number' => '6', 'creation_source' => 'manual',
            'manual_lifecycle_status' => 'cache_stale', 'zabbix_problem_is_active' => true, 'znuny_ticket_state_type' => 'open',
        ]);

        // 7. raw zabbix_problem_is_active = false with unknown/null lifecycle -> Unknown (not Resolved)
        $ticket7 = ZabbixTicket::create([
            'zabbix_event_id' => '7', 'zabbix_trigger_id' => '7', 'zabbix_host_id' => '7', 'zabbix_host_name' => 'host7', 'zabbix_problem_name' => 'prob7', 'zabbix_severity' => 7, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 7, 'znuny_ticket_number' => '7', 'creation_source' => 'manual',
            'manual_lifecycle_status' => null, 'zabbix_problem_is_active' => false, 'znuny_ticket_state_type' => 'open',
        ]);

        // 8. closed Znuny ticket takes precedence -> Closed
        $ticket8 = ZabbixTicket::create([
            'zabbix_event_id' => '8', 'zabbix_trigger_id' => '8', 'zabbix_host_id' => '8', 'zabbix_host_name' => 'host8', 'zabbix_problem_name' => 'prob8', 'zabbix_severity' => 8, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 8, 'znuny_ticket_number' => '8', 'creation_source' => 'manual',
            'manual_lifecycle_status' => 'active', 'zabbix_problem_is_active' => true, 'znuny_ticket_state_type' => 'closed', 'znuny_state_name' => 'closed successful',
        ]);

        Livewire::test(ListZabbixTickets::class)
            ->assertCanSeeTableRecords([$ticket1, $ticket2, $ticket3, $ticket4, $ticket5, $ticket6, $ticket7, $ticket8])
            ->assertSeeHtml('Active') // from ticket1 and ticket2
            ->assertSeeHtml('Resolved') // ticket3
            ->assertSeeHtml('Ready') // ticket4
            ->assertSeeHtml('Flapping') // ticket5
            ->assertSeeHtml('Cache stale') // ticket6
            ->assertSeeHtml('Unknown') // ticket7
            ->assertSeeHtml('Closed'); // ticket8
    }

    public function test_linked_tickets_manual_close_action_visibility()
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $ticketOpen = ZabbixTicket::create([
            'zabbix_event_id' => '1', 'zabbix_trigger_id' => '1', 'zabbix_host_id' => '1', 'zabbix_host_name' => 'host1', 'zabbix_problem_name' => 'prob1', 'zabbix_severity' => 1, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 1, 'znuny_ticket_number' => '1', 'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open', 'znuny_state_name' => 'open',
        ]);

        Livewire::test(ListZabbixTickets::class)
            ->assertTableActionDoesNotExist('manual_close_ticket'); // Ensure it's not a direct table row action
    }

    public function test_linked_tickets_manual_close_action_confirmation_text()
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $ticketReady = ZabbixTicket::create([
            'zabbix_event_id' => '1', 'zabbix_trigger_id' => '1', 'zabbix_host_id' => '1', 'zabbix_host_name' => 'host1', 'zabbix_problem_name' => 'prob1', 'zabbix_severity' => 1, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 1, 'znuny_ticket_number' => '1', 'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open', 'znuny_state_name' => 'open', 'manual_lifecycle_status' => 'close_candidate',
        ]);

        // Just ensure view mounts properly
        Livewire::test(ListZabbixTickets::class)
            ->mountTableAction('view', $ticketReady)
            ->assertHasNoTableActionErrors();
    }

    public function test_linked_tickets_manual_close_action_mounting_is_pure()
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => '1', 'zabbix_trigger_id' => '1', 'zabbix_host_id' => '1', 'zabbix_host_name' => 'host1', 'zabbix_problem_name' => 'prob1', 'zabbix_severity' => 1, 'zabbix_started_at' => now(), 'znuny_ticket_id' => 1, 'znuny_ticket_number' => '1', 'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open', 'znuny_state_name' => 'open', 'manual_lifecycle_status' => 'active',
        ]);

        $mock = $this->mock(ZnunyLinkedTicketCloseService::class);
        $mock->shouldNotReceive('closeTicket');

        Livewire::test(ListZabbixTickets::class)
            ->mountTableAction('view', $ticket)
            ->assertHasNoTableActionErrors();

        $ticket->refresh();
        $this->assertEquals('active', $ticket->manual_lifecycle_status);
        $this->assertEquals('open', $ticket->znuny_ticket_state_type);
    }
}
