<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Pages\MySettings;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class MySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_diagnostic_panel_toggles()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(MySettings::class)
            ->assertSuccessful()
            ->assertSee('Admin UI Preferences')
            ->assertSee('Show Current Problems polling status panel')
            ->assertSee('Show Scheduled Tasks status panel');
    }

    public function test_operator_viewer_do_not_see_diagnostic_panel_toggles()
    {
        $operator = User::factory()->create(['role' => 'operator']);

        Livewire::actingAs($operator)
            ->test(MySettings::class)
            ->assertSuccessful()
            ->assertDontSee('Admin UI Preferences')
            ->assertDontSee('Show Current Problems polling status panel')
            ->assertDontSee('Show Scheduled Tasks status panel');

        $viewer = User::factory()->create(['role' => 'viewer']);

        Livewire::actingAs($viewer)
            ->test(MySettings::class)
            ->assertSuccessful()
            ->assertDontSee('Admin UI Preferences');
    }

    public function test_user_can_save_default_landing_page()
    {
        $admin = User::factory()->create(['role' => 'admin', 'default_landing_page' => 'current-zabbix-problems']);

        Livewire::actingAs($admin)
            ->test(MySettings::class)
            ->fillForm(['default_landing_page' => 'znuny-ticket-workspace'])
            ->call('save')
            ->assertHasNoFormErrors();

        $admin->refresh();
        $this->assertEquals('znuny-ticket-workspace', $admin->default_landing_page);
    }

    public function test_create_ticket_option_is_not_available_for_viewer()
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        Livewire::actingAs($viewer)
            ->test(MySettings::class)
            ->assertSee('Current problems')
            ->assertDontSee('Create Ticket');
    }

    public function test_user_can_change_own_password()
    {
        $user = User::factory()->create(['role' => 'operator', 'password' => Hash::make('oldpassword')]);

        Livewire::actingAs($user)
            ->test(MySettings::class)
            ->fillForm([
                'current_password' => 'oldpassword',
                'new_password' => 'newpassword123',
                'new_password_confirmation' => 'newpassword123',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $user->password));
    }

    public function test_saving_preferences_without_password_does_not_change_password()
    {
        $user = User::factory()->create(['role' => 'operator', 'password' => Hash::make('oldpassword')]);

        Livewire::actingAs($user)
            ->test(MySettings::class)
            ->fillForm([
                'default_landing_page' => 'znuny-ticket-workspace',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertTrue(Hash::check('oldpassword', $user->password));
        $this->assertEquals('znuny-ticket-workspace', $user->default_landing_page);
    }

    public function test_admin_can_disable_polling_panel_and_panel_disappears()
    {
        $admin = User::factory()->create(['role' => 'admin', 'show_current_problems_status_panel' => true]);

        Livewire::actingAs($admin)
            ->test(MySettings::class)
            ->fillForm([
                'show_current_problems_status_panel' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $admin->refresh();
        $this->assertFalse($admin->show_current_problems_status_panel);
    }

    public function test_admin_can_disable_scheduled_tasks_panel()
    {
        $admin = User::factory()->create(['role' => 'admin', 'show_scheduled_tasks_status_panel' => true]);

        Livewire::actingAs($admin)
            ->test(MySettings::class)
            ->fillForm([
                'show_scheduled_tasks_status_panel' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $admin->refresh();
        $this->assertFalse($admin->show_scheduled_tasks_status_panel);
    }

    public function test_my_settings_is_hidden_from_navigation()
    {
        $this->assertFalse(MySettings::shouldRegisterNavigation());
    }

    public function test_my_settings_is_in_user_menu()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $panel = Filament::getPanel('admin');

        $menuItems = $panel->getUserMenuItems();

        $found = false;
        foreach ($menuItems as $item) {
            if ($item->getLabel() === 'My Settings' && $item->getIcon() === 'heroicon-o-cog-6-tooth') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'My Settings is not registered in the Filament user menu.');
    }
}
