<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ZabbixTickets\Pages\ListZabbixTickets;
use App\Models\Setting;
use App\Models\User;
use App\Models\ZabbixTicket;
use App\Services\Znuny\ZnunyClient;
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

    public function test_linked_tickets_page_has_refresh_and_sync_actions()
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test(ListZabbixTickets::class)
            ->assertActionExists('refresh')
            ->assertActionExists('sync_tickets')
            ->callAction('refresh')
            ->assertSuccessful();
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
}
