<?php

namespace Tests\Feature\Filament\Resources;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_edit_form_shows_both_diagnostic_panel_toggles_when_editing_an_admin_user()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $targetAdmin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $targetAdmin->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Admin UI Preferences')
            ->assertSee('Show Current Problems polling status panel')
            ->assertSee('Show Znuny closed ticket status panel');
    }

    public function test_toggles_are_hidden_or_disabled_when_editing_an_operator_or_viewer_user()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $targetOperator = User::factory()->create(['role' => 'operator']);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $targetOperator->getRouteKey()])
            ->assertSuccessful()
            ->assertDontSee('Admin UI Preferences')
            ->assertDontSee('Show Current Problems polling status panel')
            ->assertDontSee('Show Znuny closed ticket status panel');
    }

    public function test_saving_the_toggles_persists_the_values()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $targetAdmin = User::factory()->create([
            'role' => 'admin',
            'show_current_problems_status_panel' => true,
            'show_znuny_closed_ticket_status_panel' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $targetAdmin->getRouteKey()])
            ->fillForm([
                'show_current_problems_status_panel' => false,
                'show_znuny_closed_ticket_status_panel' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $targetAdmin->refresh();

        $this->assertFalse($targetAdmin->show_current_problems_status_panel);
        $this->assertFalse($targetAdmin->show_znuny_closed_ticket_status_panel);
    }

    public function test_creating_a_new_admin_gets_enabled_defaults_unless_explicitly_changed()
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
    }
}
