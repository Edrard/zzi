<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Pages\ZnunyTicketWorkspace;
use App\Models\User;
use App\Services\Znuny\ZnunyTicketCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

class ZnunyTicketWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::flushall();
    }

    protected function seedTicket(array $ticketOverrides): void
    {
        $ticket = array_merge([
            'TicketID' => 1,
            'TicketNumber' => '100000000',
            'Title' => 'Default',
            'QueueID' => 1,
            'Queue' => 'Raw',
            'OwnerID' => 1,
            'Owner' => 'Admin',
            'StateID' => 1,
            'State' => 'new',
            'StateType' => 'new',
            'PriorityID' => 1,
            'Priority' => '3 normal',
            'TypeID' => 1,
            'Type' => 'Unclassified',
            'Changed' => now()->toDateTimeString(),
            'Created' => now()->subDay()->toDateTimeString(),
            'ArticleCount' => 1,
        ], $ticketOverrides);

        app(ZnunyTicketCacheService::class)->upsertOrRefreshFromSearchResult($ticket);
    }

    public function test_it_renders_without_calling_znuny_api()
    {
        $user = User::factory()->create(['role' => 'operator']);

        // Fake Znuny API to fail if called to ensure UI does not call it
        Http::fake([
            '*' => Http::response('Should not be called', 500),
        ]);

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Test Ticket', 'StateType' => 'open']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertSee('TN101')
            ->assertSee('Test Ticket');

        Http::assertNothingSent();
    }

    public function test_empty_state_shows_correct_message()
    {
        $user = User::factory()->create(['role' => 'operator']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->set('stateTypeFilter', [])
            ->assertSee('Ticket cache is empty')
            ->assertSee('Run the Ticket Workspace cache warmer');
    }

    public function test_it_applies_livewire_filters()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Apple issue', 'StateType' => 'new']);
        $this->seedTicket(['TicketID' => 102, 'TicketNumber' => 'TN102', 'Title' => 'Banana issue', 'StateType' => 'closed']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new', 'closed'])
            ->set('search', 'Apple')
            ->assertSee('TN101')
            ->assertDontSee('TN102')
            ->set('search', '')
            ->set('stateTypeFilter', ['closed'])
            ->assertSee('TN102')
            ->assertDontSee('TN101');
    }

    public function test_it_sorts_tickets_correctly()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'A Ticket', 'Changed' => '2023-01-01 10:00:00', 'StateType' => 'new']);
        $this->seedTicket(['TicketID' => 102, 'TicketNumber' => 'TN102', 'Title' => 'Z Ticket', 'Changed' => '2023-01-02 10:00:00', 'StateType' => 'new']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new'])
            ->call('sortBy', 'Title')
            ->assertSeeInOrder(['Z Ticket', 'A Ticket']);
    }

    public function test_it_paginates_and_limits_per_page()
    {
        $user = User::factory()->create(['role' => 'operator']);

        for ($i = 1; $i <= 10; $i++) {
            $this->seedTicket(['TicketID' => 100 + $i, 'TicketNumber' => 'TN'.(100 + $i), 'Title' => 'Ticket '.$i, 'StateType' => 'new']);
        }

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new'])
            ->set('perPage', 5)
            ->assertSee('TN110')
            ->assertDontSee('TN101') // on page 2
            ->set('page', 2)
            ->assertDontSee('TN110')
            ->assertSee('TN101');
    }

    public function test_queue_and_owner_filters_work()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Q10_O20', 'QueueID' => 10, 'OwnerID' => 20, 'StateType' => 'new']);
        $this->seedTicket(['TicketID' => 102, 'TicketNumber' => 'TN102', 'Title' => 'Q11_O21', 'QueueID' => 11, 'OwnerID' => 21, 'StateType' => 'new']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new'])
            ->set('queueFilter', 10)
            ->assertSee('TN101')
            ->assertDontSee('TN102')
            ->set('queueFilter', null)
            ->set('ownerFilter', 21)
            ->assertSee('TN102')
            ->assertDontSee('TN101');
    }

    public function test_clicking_row_opens_details_modal_with_cached_data()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Test Details', 'StateType' => 'new', 'CustomerUserID' => 'client@example.com']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new'])
            ->call('openTicketDetails', 101)
            ->assertSet('selectedTicketId', 101)
            ->assertSee('client@example.com')
            ->assertDispatched('open-modal');
    }

    public function test_icon_legend_is_rendered()
    {
        $user = User::factory()->create(['role' => 'operator']);

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Filament\Pages\ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertSee('Linked to active Zabbix problem')
            ->assertSee('Linked to resolved Zabbix problem')
            ->assertSee('Active problem on closed/merged ticket');
    }

    public function test_state_type_filter_accepts_multiple_values()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 201, 'TicketNumber' => 'TN201', 'Title' => 'New', 'StateType' => 'new']);
        $this->seedTicket(['TicketID' => 202, 'TicketNumber' => 'TN202', 'Title' => 'Open', 'StateType' => 'open']);
        $this->seedTicket(['TicketID' => 203, 'TicketNumber' => 'TN203', 'Title' => 'Closed', 'StateType' => 'closed']);

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Filament\Pages\ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new', 'open'])
            ->assertSee('TN201')
            ->assertSee('TN202')
            ->assertDontSee('TN203');
    }

    public function test_get_refresh_interval_string_uses_setting()
    {
        $user = User::factory()->create(['role' => 'operator']);

        \App\Models\Setting::updateOrCreate(
            ['key' => 'znuny_ticket_cache_refresh_interval_minutes'],
            ['value' => 5, 'type' => 'integer']
        );

        $page = new \App\Filament\Pages\ZnunyTicketWorkspace();
        $this->assertEquals('300s', $page->getRefreshIntervalString());

        \App\Models\Setting::updateOrCreate(
            ['key' => 'znuny_ticket_cache_refresh_interval_minutes'],
            ['value' => 1, 'type' => 'integer']
        );

        $this->assertEquals('60s', $page->getRefreshIntervalString());
    }

    public function test_page_includes_livewire_polling()
    {
        $user = User::factory()->create(['role' => 'operator']);

        \App\Models\Setting::updateOrCreate(
            ['key' => 'znuny_ticket_cache_refresh_interval_minutes'],
            ['value' => 5, 'type' => 'integer']
        );

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Filament\Pages\ZnunyTicketWorkspace::class)
            ->assertSeeHtml('wire:poll.300s');
    }

    public function test_manual_refresh_calls_existing_command()
    {
        $user = User::factory()->create(['role' => 'operator']);

        \Illuminate\Support\Facades\Artisan::shouldReceive('call')
            ->once()
            ->with('znuny:warm-ticket-workspace-cache')
            ->andReturn(0);
        \Illuminate\Support\Facades\Artisan::shouldReceive('output')
            ->andReturn('Cache warming complete.');

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Filament\Pages\ZnunyTicketWorkspace::class)
            ->call('refreshFromZnuny')
            ->assertNotified()
            ->assertSet('page', 1);
    }

    public function test_manual_refresh_handles_failure()
    {
        $user = User::factory()->create(['role' => 'operator']);

        \Illuminate\Support\Facades\Artisan::shouldReceive('call')
            ->once()
            ->with('znuny:warm-ticket-workspace-cache')
            ->andReturn(1);
        \Illuminate\Support\Facades\Artisan::shouldReceive('output')
            ->andReturn('Failed output');

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Filament\Pages\ZnunyTicketWorkspace::class)
            ->call('refreshFromZnuny')
            ->assertNotified();
    }
}
