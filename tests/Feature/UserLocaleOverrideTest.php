<?php

namespace Tests\Feature;

use App\Filament\Pages\MySettings;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\Support\ApplicationLocaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class UserLocaleOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_en_user_null_resolves_to_en(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'en', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $user = User::factory()->create(['ui_locale' => null]);
        $service = app(ApplicationLocaleService::class);

        $this->assertSame('en', $service->resolve($user));
    }

    public function test_global_uk_user_null_resolves_to_uk(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'uk', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $user = User::factory()->create(['ui_locale' => null]);
        $service = app(ApplicationLocaleService::class);

        $this->assertSame('uk', $service->resolve($user));
    }

    public function test_global_uk_user_en_resolves_to_en(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'uk', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $user = User::factory()->create(['ui_locale' => 'en']);
        $service = app(ApplicationLocaleService::class);

        $this->assertSame('en', $service->resolve($user));
    }

    public function test_global_en_user_uk_resolves_to_uk(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'en', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $user = User::factory()->create(['ui_locale' => 'uk']);
        $service = app(ApplicationLocaleService::class);

        $this->assertSame('uk', $service->resolve($user));
    }

    public function test_invalid_user_locale_falls_through_to_global_uk(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'uk', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $user = User::factory()->create(['ui_locale' => 'fr']);
        $service = app(ApplicationLocaleService::class);

        $this->assertSame('uk', $service->resolve($user));
    }

    public function test_invalid_global_and_no_user_override_resolves_to_en(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'fr', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $user = User::factory()->create(['ui_locale' => null]);
        $service = app(ApplicationLocaleService::class);

        $this->assertSame('en', $service->resolve($user));
    }

    public function test_unauthenticated_resolution_uses_global_locale(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'uk', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $service = app(ApplicationLocaleService::class);

        $this->assertSame('uk', $service->resolve(null));
    }

    public function test_valid_user_override_wins_over_invalid_global_setting(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'fr', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $user = User::factory()->create(['ui_locale' => 'uk']);
        $service = app(ApplicationLocaleService::class);

        $this->assertSame('uk', $service->resolve($user));
    }

    // Middleware Coverage

    public function test_authenticated_user_override_is_applied_by_middleware(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'en', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $user = User::factory()->create(['ui_locale' => 'uk']);

        $this->actingAs($user)->get('/admin');

        $this->assertSame('uk', App::getLocale());
    }

    public function test_authenticated_user_without_override_receives_global_locale(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'uk', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $user = User::factory()->create(['ui_locale' => null]);

        $this->actingAs($user)->get('/admin');

        $this->assertSame('uk', App::getLocale());
    }

    public function test_unauthenticated_admin_login_page_receives_global_locale(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'uk', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $this->get('/admin/login');

        $this->assertSame('uk', App::getLocale());
    }

    public function test_invalid_personal_value_falls_through_to_global_locale_in_middleware(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'uk', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $user = User::factory()->create(['ui_locale' => 'es']);

        $this->actingAs($user)->get('/admin');

        $this->assertSame('uk', App::getLocale());
    }

    // My Settings Form Coverage

    public function test_personal_locale_select_exists_with_correct_options(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'ui_locale' => null]);

        $component = Livewire::actingAs($user)->test(MySettings::class);

        $uiLocaleField = null;
        $schema = $component->instance()->getForm('form')->getComponents();

        $search = function ($components) use (&$search, &$uiLocaleField) {
            foreach ($components as $c) {
                if (method_exists($c, 'getName') && $c->getName() === 'ui_locale') {
                    $uiLocaleField = $c;
                }
                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };
        $search($schema);

        $this->assertNotNull($uiLocaleField);
        $options = $uiLocaleField->getOptions();

        $expected = array_merge(
            ['__system__' => __('settings.my_settings.ui_locale.system_default')],
            app(ApplicationLocaleService::class)->options()
        );

        $this->assertSame($expected, $options);
    }

    public function test_user_with_null_locale_sees_system_default_selected(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'ui_locale' => null]);

        Livewire::actingAs($user)
            ->test(MySettings::class)
            ->assertFormSet(['ui_locale' => '__system__']);
    }

    public function test_user_with_en_locale_sees_en_selected(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'ui_locale' => 'en']);

        Livewire::actingAs($user)
            ->test(MySettings::class)
            ->assertFormSet(['ui_locale' => 'en']);
    }

    public function test_user_with_uk_locale_sees_uk_selected(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'ui_locale' => 'uk']);

        Livewire::actingAs($user)
            ->test(MySettings::class)
            ->assertFormSet(['ui_locale' => 'uk']);
    }

    public function test_invalid_locale_fails_validation(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'en', 'type' => 'string']);
        SettingsService::clearAllCaches();
        App::setLocale('uk');

        $user = User::factory()->create(['role' => 'admin', 'ui_locale' => 'uk']);

        Livewire::actingAs($user)
            ->test(MySettings::class)
            ->fillForm([
                'ui_locale' => 'es',
            ])
            ->call('save')
            ->assertHasFormErrors(['ui_locale'])
            ->assertNoRedirect();

        $this->assertSame('uk', $user->fresh()->ui_locale);
        $this->assertSame('uk', App::getLocale());
    }

    // My Settings Save & Redirect Coverage

    public function test_saving_personal_locale_from_null_to_uk_redirects_and_applies(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'en', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $user = User::factory()->create(['role' => 'admin', 'ui_locale' => null, 'default_landing_page' => 'current-zabbix-problems']);

        Livewire::actingAs($user)
            ->test(MySettings::class)
            ->fillForm([
                'ui_locale' => 'uk',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(MySettings::getUrl());

        $this->assertSame('uk', $user->fresh()->ui_locale);
        $this->assertSame('uk', App::getLocale());
    }

    public function test_saving_personal_locale_from_null_to_en_while_system_uk_redirects_and_applies(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'uk', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $user = User::factory()->create(['role' => 'admin', 'ui_locale' => null, 'default_landing_page' => 'current-zabbix-problems']);

        Livewire::actingAs($user)
            ->test(MySettings::class)
            ->fillForm([
                'ui_locale' => 'en',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(MySettings::getUrl());

        $this->assertSame('en', $user->fresh()->ui_locale);
        $this->assertSame('en', App::getLocale());
    }

    public function test_saving_personal_override_to_system_default_redirects_if_effective_locale_changes(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'uk', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $user = User::factory()->create(['role' => 'admin', 'ui_locale' => 'en', 'default_landing_page' => 'current-zabbix-problems']);

        Livewire::actingAs($user)
            ->test(MySettings::class)
            ->fillForm([
                'ui_locale' => '__system__',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(MySettings::getUrl());

        $this->assertNull($user->fresh()->ui_locale);
        $this->assertSame('uk', App::getLocale());
    }

    public function test_saving_personal_en_to_system_en_stores_null_and_does_not_redirect(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'en', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $user = User::factory()->create(['role' => 'admin', 'ui_locale' => 'en', 'default_landing_page' => 'current-zabbix-problems']);

        Livewire::actingAs($user)
            ->test(MySettings::class)
            ->fillForm([
                'ui_locale' => '__system__',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNoRedirect();

        $this->assertNull($user->fresh()->ui_locale);
        $this->assertSame('en', App::getLocale());
    }

    public function test_saving_personal_null_to_explicit_en_while_system_is_en_does_not_redirect(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'en', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $user = User::factory()->create(['role' => 'admin', 'ui_locale' => null, 'default_landing_page' => 'current-zabbix-problems']);

        Livewire::actingAs($user)
            ->test(MySettings::class)
            ->fillForm([
                'ui_locale' => 'en',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNoRedirect();

        $this->assertSame('en', $user->fresh()->ui_locale);
        $this->assertSame('en', App::getLocale());
    }

    public function test_saving_profile_field_without_effective_locale_change_does_not_redirect(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'en', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $user = User::factory()->create(['role' => 'admin', 'ui_locale' => 'uk', 'default_landing_page' => 'current-zabbix-problems']);

        Livewire::actingAs($user)
            ->test(MySettings::class)
            ->fillForm([
                'ui_locale' => 'uk',
                'show_scheduled_tasks_status_panel' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNoRedirect();

        $this->assertFalse($user->fresh()->show_scheduled_tasks_status_panel);
        $this->assertSame('uk', $user->fresh()->ui_locale);
        $this->assertSame('uk', App::getLocale());
    }
    // Database and Defaults

    public function test_users_table_has_ui_locale_column(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'ui_locale'));
    }

    public function test_new_factory_user_has_null_ui_locale_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->ui_locale);
    }
}
