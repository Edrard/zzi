<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Pages\CreateTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CreateTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_ticket_page_can_be_rendered()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/admin/create-ticket')
            ->assertSuccessful();

        Livewire::actingAs($admin)
            ->test(CreateTicket::class)
            ->assertSuccessful()
            ->assertFormExists()
            ->assertFormFieldExists('queue')
            ->assertFormFieldExists('title')
            ->assertFormFieldExists('body');
    }
}
