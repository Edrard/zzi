<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Pages\ZnunyTicketWorkspace;
use App\Models\User;
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

    public function test_it_renders_without_calling_znuny_api()
    {
        $user = User::factory()->create(['role' => 'operator']);

        // Fake Znuny API to fail if called to ensure UI does not call it
        Http::fake([
            '*' => Http::response('Should not be called', 500),
        ]);

        $ticket1 = ['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Test Ticket', 'StateType' => 'open'];
        Redis::set('znuny:ticket:101', json_encode($ticket1));

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
            ->assertSee('Ticket cache is empty')
            ->assertSee('Run the Ticket Workspace cache warmer');
    }

    public function test_it_applies_livewire_filters()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $ticket1 = ['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Apple issue', 'StateType' => 'new'];
        $ticket2 = ['TicketID' => 102, 'TicketNumber' => 'TN102', 'Title' => 'Banana issue', 'StateType' => 'closed'];

        Redis::set('znuny:ticket:101', json_encode($ticket1));
        Redis::set('znuny:ticket:102', json_encode($ticket2));

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('search', 'Apple')
            ->assertSee('TN101')
            ->assertDontSee('TN102')
            ->set('search', '')
            ->set('stateTypeFilter', 'closed')
            ->assertSee('TN102')
            ->assertDontSee('TN101');
    }

    public function test_it_sorts_tickets_correctly()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $ticket1 = ['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'A Ticket', 'Changed' => '2023-01-01 10:00:00'];
        $ticket2 = ['TicketID' => 102, 'TicketNumber' => 'TN102', 'Title' => 'Z Ticket', 'Changed' => '2023-01-02 10:00:00'];

        Redis::set('znuny:ticket:101', json_encode($ticket1));
        Redis::set('znuny:ticket:102', json_encode($ticket2));

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->call('sortBy', 'Title')
            ->assertSeeInOrder(['Z Ticket', 'A Ticket']);
    }
}
