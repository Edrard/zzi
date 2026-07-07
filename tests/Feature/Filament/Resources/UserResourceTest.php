<?php

namespace Tests\Feature\Filament\Resources;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_new_admin_gets_enabled_defaults()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'New Admin',
                'email' => 'admin1@example.com',
                'role' => 'admin',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $newAdmin = User::where('email', 'admin1@example.com')->first();

        $this->assertTrue($newAdmin->show_current_problems_status_panel);
        $this->assertTrue($newAdmin->show_znuny_closed_ticket_status_panel);
        $this->assertEquals('current-zabbix-problems', $newAdmin->default_landing_page);
    }
}
