<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsLocalizedTicketTemplatePresetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::updateOrCreate(['key' => 'znuny_manual_ticket_footer'], ['value' => 'Old Footer', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'linked_ticket_manual_close_default_reason'], ['value' => 'Old Close', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'manual_ticket_reopen_note_template'], ['value' => 'Old Reopen', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'mail_notifications_enabled'], ['value' => 'false', 'type' => 'boolean']);

        SettingsService::clearAllCaches();
    }

    public function test_en_uk_parity_and_exact_values()
    {
        $en = __('settings.ticket_template_presets', [], 'en');
        $uk = __('settings.ticket_template_presets', [], 'uk');

        $this->assertEquals(array_keys($en['defaults']), array_keys($uk['defaults']));
        $this->assertEquals(array_keys($en['action']), array_keys($uk['action']));
        $this->assertEquals(array_keys($en['notifications']), array_keys($uk['notifications']));

        $this->assertEquals('Created manually by Zabbix Znuny Integration.', $en['defaults']['znuny_manual_ticket_footer']);
        $this->assertEquals('Manual close from Linked Tickets UI.', $en['defaults']['linked_ticket_manual_close_default_reason']);
        $this->assertEquals('Reopening this ticket because the linked Zabbix problem became active again within the configured reopen window.', $en['defaults']['manual_ticket_reopen_note_template']);

        $this->assertEquals('Створено вручну через Zabbix Znuny Integration.', $uk['defaults']['znuny_manual_ticket_footer']);
        $this->assertEquals('Ручне закриття через інтерфейс пов’язаних звернень.', $uk['defaults']['linked_ticket_manual_close_default_reason']);
        $this->assertEquals('Повторно відкриваємо це звернення, оскільки пов’язана проблема Zabbix знову стала активною в межах налаштованого періоду повторного відкриття.', $uk['defaults']['manual_ticket_reopen_note_template']);
    }

    public function test_preset_contains_exactly_three_approved_keys()
    {
        $en = __('settings.ticket_template_presets', [], 'en');
        $this->assertEqualsCanonicalizing([
            'znuny_manual_ticket_footer',
            'linked_ticket_manual_close_default_reason',
            'manual_ticket_reopen_note_template',
        ], array_keys($en['defaults']));
    }

    public function test_action_uses_active_locale_with_fallback_behavior()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        App::setLocale('uk');

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->mountAction(TestAction::make('loadLocalizedTicketTemplates')->schemaComponent('localized-ticket-template-presets-actions'))
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertEquals('Створено вручну через Zabbix Znuny Integration.', Setting::where('key', 'znuny_manual_ticket_footer')->value('value'));

        App::setLocale('fr'); // Unsupported

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->mountAction(TestAction::make('loadLocalizedTicketTemplates')->schemaComponent('localized-ticket-template-presets-actions'))
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertEquals('Created manually by Zabbix Znuny Integration.', Setting::where('key', 'znuny_manual_ticket_footer')->value('value'));
    }

    public function test_action_is_wired_into_schema_and_requires_confirmation()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $action = TestAction::make('loadLocalizedTicketTemplates')
            ->schemaComponent('localized-ticket-template-presets-actions');

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->assertActionExists($action)
            ->assertActionVisible($action)
            ->mountAction($action);
    }

    public function test_only_three_keys_are_persisted_and_unrelated_data_is_ignored()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $action = TestAction::make('loadLocalizedTicketTemplates')
            ->schemaComponent('localized-ticket-template-presets-actions');

        App::setLocale('en');

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->set('data.mail_notifications_enabled', true) // Dirty unrelated setting
            ->mountAction($action)
            ->callMountedAction();

        $this->assertEquals('Created manually by Zabbix Znuny Integration.', Setting::where('key', 'znuny_manual_ticket_footer')->value('value'));
        $this->assertEquals('false', Setting::where('key', 'mail_notifications_enabled')->value('value'));
    }

    public function test_in_memory_form_values_are_refreshed()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $action = TestAction::make('loadLocalizedTicketTemplates')
            ->schemaComponent('localized-ticket-template-presets-actions');

        App::setLocale('en');

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->set('data.mail_notifications_enabled', true)
            ->mountAction($action)
            ->callMountedAction()
            ->assertSet('data.znuny_manual_ticket_footer', 'Created manually by Zabbix Znuny Integration.')
            ->assertSet('data.linked_ticket_manual_close_default_reason', 'Manual close from Linked Tickets UI.')
            ->assertSet('data.manual_ticket_reopen_note_template', 'Reopening this ticket because the linked Zabbix problem became active again within the configured reopen window.')
            ->assertSet('data.mail_notifications_enabled', true); // Unrelated dirty data preserved in form
    }

    public function test_success_ui_text_is_localized()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $action = TestAction::make('loadLocalizedTicketTemplates')
            ->schemaComponent('localized-ticket-template-presets-actions');

        App::setLocale('uk');

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->mountAction($action)
            ->callMountedAction()
            ->assertNotified('Стандартні шаблони збережено');
    }
}
