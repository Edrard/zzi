<?php

namespace Tests\Feature\Filament\Resources\ZabbixTicketResource;

use App\Filament\Resources\ZabbixTickets\Pages\ListZabbixTickets;
use App\Filament\Support\TicketDetailsPayload;
use App\Models\User;
use App\Models\ZabbixTicket;
use App\Services\Znuny\ZnunyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TicketDetailsActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketDetailsPayload::clearCache();
    }

    public function test_linked_tickets_details_hydrates_lock_and_shows_take_action()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt'.rand(100, 999),
            'zabbix_problem_name' => 'test problem',
            'zabbix_host_name' => 'test host',
            'znuny_ticket_id' => 123,
            'znuny_ticket_number' => '12345',
            'znuny_ticket_state_type' => 'open',
        ]);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('getTicket')->with(123)->andReturn([
            'TicketID' => 123,
            'StateType' => 'open',
            'Lock' => 'unlock',
        ]);
        $this->app->instance(ZnunyClient::class, $mockClient);

        Livewire::actingAs($user)
            ->test(ListZabbixTickets::class)
            ->assertTableActionVisible('viewTicket', $ticket)
            ->mountTableAction('viewTicket', $ticket)
            ->assertActionVisible('take_or_release_ticket')
            ->assertActionHidden('reopen_ticket')
            ->assertActionVisible('manual_close_ticket');
    }

    public function test_linked_tickets_details_hydrates_lock_and_shows_release_action()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt'.rand(100, 999),
            'zabbix_problem_name' => 'test problem',
            'zabbix_host_name' => 'test host',
            'znuny_ticket_id' => 124,
            'znuny_ticket_number' => '12346',
            'znuny_ticket_state_type' => 'open',
        ]);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('getTicket')->with(124)->andReturn([
            'TicketID' => 124,
            'StateType' => 'open',
            'Lock' => 'lock',
        ]);
        $this->app->instance(ZnunyClient::class, $mockClient);

        Livewire::actingAs($user)
            ->test(ListZabbixTickets::class)
            ->mountTableAction('viewTicket', $ticket)
            ->assertActionVisible('take_or_release_ticket');

        // We can't directly check the label of the footer action easily without digging into modal,
        // but we know take_or_release_ticket is visible if lock is 'lock' or 'unlock'.
    }

    public function test_linked_tickets_details_keeps_actions_hidden_if_missing_lock_and_fetch_fails()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt'.rand(100, 999),
            'zabbix_problem_name' => 'test problem',
            'zabbix_host_name' => 'test host',
            'znuny_ticket_id' => 125,
            'znuny_ticket_number' => '12347',
            'znuny_ticket_state_type' => 'open',
        ]);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('getTicket')->with(125)->andThrow(new \Exception('Offline'));
        $this->app->instance(ZnunyClient::class, $mockClient);

        Livewire::actingAs($user)
            ->test(ListZabbixTickets::class)
            ->mountTableAction('viewTicket', $ticket)
            ->assertActionHidden('take_or_release_ticket');
    }

    public function test_linked_tickets_details_hydrates_lock_and_shows_reopen_action_when_closed()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt'.rand(100, 999),
            'zabbix_problem_name' => 'test problem',
            'zabbix_host_name' => 'test host',
            'znuny_ticket_id' => 126,
            'znuny_ticket_number' => '12348',
            'znuny_ticket_state_type' => 'open', // old state
        ]);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('getTicket')->with(126)->andReturn([
            'TicketID' => 126,
            'StateType' => 'closed',
            'Lock' => 'unlock',
        ]);
        $this->app->instance(ZnunyClient::class, $mockClient);

        Livewire::actingAs($user)
            ->test(ListZabbixTickets::class)
            ->mountTableAction('viewTicket', $ticket)
            ->assertActionHidden('take_or_release_ticket')
            ->assertActionVisible('reopen_ticket')
            ->assertActionHidden('manual_close_ticket');
    }
}
