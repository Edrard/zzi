<?php

namespace Tests\Feature;

use App\Filament\Pages\CreateTicket;
use App\Filament\Pages\CurrentZabbixProblems;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Settings;
use App\Filament\Pages\ZnunyTicketWorkspace;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\ScheduledZnunyTaskRuns\ScheduledZnunyTaskRunResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\ZabbixProblemFilters\ZabbixProblemFilterResource;
use App\Filament\Resources\ZabbixTickets\ZabbixTicketResource;
use App\Http\Middleware\SetApplicationLocale;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\Support\ApplicationLocaleService;
use App\Support\Settings\DefaultSettings;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalizationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_locale_defaults_to_en(): void
    {
        $appConfig = file_get_contents(config_path('app.php'));

        $this->assertStringContainsString(
            "'locale' => env('APP_LOCALE', 'en'),",
            $appConfig,
            'config/app.php does not declare the correct locale default'
        );

        $this->assertStringContainsString(
            "'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),",
            $appConfig,
            'config/app.php does not declare the correct fallback_locale default'
        );

        $envExample = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('APP_LOCALE=en', $envExample);
        $this->assertStringContainsString('APP_FALLBACK_LOCALE=en', $envExample);
    }

    public function test_supported_locales_are_uk_and_en(): void
    {
        $service = app(ApplicationLocaleService::class);
        $this->assertEquals(['uk', 'en'], $service->supportedLocales());
    }

    public function test_default_locale_is_en(): void
    {
        $service = app(ApplicationLocaleService::class);
        $this->assertEquals('en', $service->defaultLocale());
    }

    public function test_normalize_accepts_uk(): void
    {
        $service = app(ApplicationLocaleService::class);
        $this->assertEquals('uk', $service->normalize('uk'));
    }

    public function test_normalize_accepts_en(): void
    {
        $service = app(ApplicationLocaleService::class);
        $this->assertEquals('en', $service->normalize('en'));
    }

    public function test_normalize_returns_en_for_invalid_values(): void
    {
        $service = app(ApplicationLocaleService::class);
        $this->assertEquals('en', $service->normalize(null));
        $this->assertEquals('en', $service->normalize(''));
        $this->assertEquals('en', $service->normalize(' '));
        $this->assertEquals('en', $service->normalize('fr'));
        $this->assertEquals('en', $service->normalize('EN'));
    }

    public function test_locale_options_are_exactly_uk_and_en(): void
    {
        $service = app(ApplicationLocaleService::class);
        $this->assertEquals([
            'uk' => 'Українська',
            'en' => 'English',
        ], $service->options());
    }

    public function test_default_settings_contains_exactly_one_ui_locale_row(): void
    {
        $defaults = DefaultSettings::all();
        $uiLocales = array_filter($defaults, fn ($d) => $d['key'] === 'ui_locale');

        $this->assertCount(1, $uiLocales);
        $uiLocale = reset($uiLocales);
        $this->assertEquals('en', $uiLocale['value']);
        $this->assertEquals('string', $uiLocale['type']);
    }

    public function test_ensure_settings_defaults_creates_missing_ui_locale(): void
    {
        $this->assertDatabaseMissing('settings', ['key' => 'ui_locale']);
        Artisan::call('app:ensure-settings-defaults');
        $this->assertDatabaseHas('settings', ['key' => 'ui_locale', 'value' => 'en']);
    }

    public function test_ensure_settings_defaults_does_not_overwrite_existing_uk(): void
    {
        Setting::create(['key' => 'ui_locale', 'value' => 'uk', 'type' => 'string']);
        Artisan::call('app:ensure-settings-defaults');
        $this->assertDatabaseHas('settings', ['key' => 'ui_locale', 'value' => 'uk']);
    }

    public function test_middleware_applies_stored_uk_during_request(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'uk', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get('/admin');

        $this->assertEquals('uk', App::getLocale());
    }

    public function test_middleware_falls_back_to_en_for_invalid_stored_value(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'fr', 'type' => 'string']);
        SettingsService::clearAllCaches();

        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get('/admin');

        $this->assertEquals('en', App::getLocale());
    }

    public function test_admin_panel_registers_locale_as_persistent_middleware(): void
    {
        $panel = Filament::getPanel('admin');

        $middleware = $panel->getMiddleware();

        $this->assertContains(SetApplicationLocale::class, $middleware, 'SetApplicationLocale is not registered for normal panel requests');

        $counts = array_count_values($middleware);
        $this->assertEquals(1, $counts[SetApplicationLocale::class], 'SetApplicationLocale is registered multiple times');

        $persistentMiddleware = app(PersistentMiddleware::class)->getPersistentMiddleware();

        $this->assertContains(SetApplicationLocale::class, $persistentMiddleware, 'SetApplicationLocale is not registered as persistent middleware');

        $this->assertNotContains(StartSession::class, $persistentMiddleware, 'StartSession was accidentally made persistent');
        $this->assertNotContains(EncryptCookies::class, $persistentMiddleware, 'EncryptCookies was accidentally made persistent');
    }

    public function test_settings_page_contains_ui_locale_select_in_general_main(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $component = Livewire::actingAs($admin)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $found = false;
        $search = function ($components) use (&$search, &$found) {
            foreach ($components as $c) {
                if (method_exists($c, 'getName') && $c->getName() === 'ui_locale') {
                    $found = true;
                    $this->assertInstanceOf(Select::class, $c);
                    $serviceOptions = app(ApplicationLocaleService::class)->options();
                    $this->assertEquals($serviceOptions, $c->getOptions());
                }
                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };

        $search($schema);
        $this->assertTrue($found);
    }

    public function test_admin_with_null_locale_is_redirected_when_global_setting_changes_effective_locale(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'uk', 'type' => 'string']);
        SettingsService::clearAllCaches();
        App::setLocale('uk');
        $admin = User::factory()->create(['role' => 'admin', 'ui_locale' => null]);

        $payload = array_merge(
            $this->getValidSettingsPayload(),
            ['ui_locale' => 'en']
        );

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm($payload)
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(Settings::getUrl());

        $this->assertSame('en', Setting::where('key', 'ui_locale')->value('value'));
        $this->assertSame('en', App::getLocale());
    }

    public function test_admin_with_personal_en_is_not_redirected_when_global_setting_changes_to_uk_but_it_is_persisted(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'en', 'type' => 'string']);
        SettingsService::clearAllCaches();
        App::setLocale('en');
        $admin = User::factory()->create(['role' => 'admin', 'ui_locale' => 'en']);

        $payload = array_merge(
            $this->getValidSettingsPayload(),
            ['ui_locale' => 'uk']
        );

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm($payload)
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNoRedirect();

        $this->assertSame('uk', Setting::where('key', 'ui_locale')->value('value'));
        // effective locale remains 'en' due to personal override
        $this->assertSame('en', App::getLocale());
    }

    public function test_saving_with_same_effective_locale_does_not_redirect(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'en', 'type' => 'string']);
        SettingsService::clearAllCaches();
        App::setLocale('en');
        $admin = User::factory()->create(['role' => 'admin']);

        $payload = array_merge(
            $this->getValidSettingsPayload(),
            ['ui_locale' => 'en']
        );

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm($payload)
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNoRedirect();
    }

    public function test_saving_another_setting_while_locale_remains_unchanged_does_not_redirect(): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'en', 'type' => 'string']);
        SettingsService::clearAllCaches();
        App::setLocale('en');
        $admin = User::factory()->create(['role' => 'admin']);

        $payload = array_merge(
            $this->getValidSettingsPayload(),
            ['ui_locale' => 'en', 'app_display_timezone' => 'Europe/Berlin']
        );

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm($payload)
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNoRedirect();
    }

    public static function invalidLocaleProvider(): array
    {
        return [
            'unsupported language' => ['fr'],
            'empty string' => [''],
            'array value' => [['uk']],
        ];
    }

    #[DataProvider('invalidLocaleProvider')]
    public function test_saving_invalid_locale_produces_validation_error_and_does_not_save(array|string $invalidValue): void
    {
        Setting::updateOrCreate(['key' => 'ui_locale'], ['value' => 'uk', 'type' => 'string']);
        SettingsService::clearAllCaches();

        App::setLocale('uk');
        $admin = User::factory()->create(['role' => 'admin']);

        $payload = array_merge(
            $this->getValidSettingsPayload(),
            ['ui_locale' => $invalidValue]
        );

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm($payload)
            ->call('save')
            ->assertHasFormErrors(['ui_locale']);

        $this->assertSame('uk', Setting::where('key', 'ui_locale')->value('value'));
        $this->assertSame('uk', App::getLocale());
    }

    public function test_translation_files_have_recursively_matching_key_structures(): void
    {
        $files = ['common.php', 'navigation.php', 'settings.php'];

        foreach ($files as $file) {
            $en = require lang_path("en/{$file}");
            $uk = require lang_path("uk/{$file}");

            $this->assertEquals(
                $this->getArrayKeysRecursive($en),
                $this->getArrayKeysRecursive($uk),
                "Key structures do not match for $file"
            );
        }
    }

    public function test_classes_resolve_english_metadata_correctly(): void
    {
        App::setLocale('en');

        $this->assertEquals('Dashboard', Dashboard::getNavigationLabel());
        $this->assertEquals('Create Ticket', CreateTicket::getNavigationLabel());
        $this->assertEquals('Current Problems', CurrentZabbixProblems::getNavigationLabel());
        $this->assertEquals('Ticket Workspace', ZnunyTicketWorkspace::getNavigationLabel());
        $this->assertEquals('Linked Ticket', ZabbixTicketResource::getModelLabel());
        $this->assertEquals('Scheduler Log', ScheduledZnunyTaskRunResource::getModelLabel());
        $this->assertEquals('Audit Log', AuditLogResource::getModelLabel());
        $this->assertEquals('Ignore Filter', ZabbixProblemFilterResource::getModelLabel());
    }

    public function test_classes_resolve_ukrainian_metadata_correctly(): void
    {
        App::setLocale('uk');

        $this->assertEquals('Інформаційна панель', Dashboard::getNavigationLabel());
        $this->assertEquals('Створити звернення', CreateTicket::getNavigationLabel());
        $this->assertEquals('Поточні проблеми', CurrentZabbixProblems::getNavigationLabel());
        $this->assertEquals('Робоча область звернень', ZnunyTicketWorkspace::getNavigationLabel());
        $this->assertEquals('Пов’язане звернення', ZabbixTicketResource::getModelLabel());
        $this->assertEquals('Запис журналу запусків', ScheduledZnunyTaskRunResource::getModelLabel());
        $this->assertEquals('Запис журналу аудиту', AuditLogResource::getModelLabel());
        $this->assertEquals('Фільтр ігнорування', ZabbixProblemFilterResource::getModelLabel());
    }

    public function test_switching_locales_in_same_process_changes_actual_labels(): void
    {
        App::setLocale('en');
        $enLabel = Dashboard::getNavigationLabel();

        App::setLocale('uk');
        $ukLabel = Dashboard::getNavigationLabel();

        $this->assertEquals('Dashboard', $enLabel);
        $this->assertEquals('Інформаційна панель', $ukLabel);
    }

    public function test_administration_navigation_sorts_remain_correct(): void
    {
        $this->assertEquals(10, Settings::getNavigationSort());
        $this->assertEquals(20, ScheduledZnunyTaskRunResource::getNavigationSort());
        $this->assertEquals(30, AuditLogResource::getNavigationSort());
        $this->assertEquals(40, UserResource::getNavigationSort());
    }

    private function getArrayKeysRecursive(array $array): array
    {
        $keys = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $keys[$key] = $this->getArrayKeysRecursive($value);
            } else {
                $keys[$key] = true;
            }
        }
        ksort($keys);

        return $keys;
    }

    private function getValidSettingsPayload(): array
    {
        return [
            'znuny_username' => 'testuser',
            'znuny_api_url' => 'http://api',
            'znuny_web_url' => 'http://web',
            'znuny_ticket_url_template' => 'url',
            'znuny_api_verify_ssl' => true,
            'znuny_api_timeout' => 10,
            'cleanup_enabled' => true,
            'cleanup_batch_size' => 1000,
            'retention_action_logs_days' => 30,
            'retention_closed_tickets_days' => 30,
            'retention_failed_jobs_days' => 30,
            'retention_resolved_days' => 30,
            'zabbix_api_url' => 'http://new.com',
            'zabbix_api_token' => '',
            'zabbix_api_timeout' => 10,
            'zabbix_api_verify_ssl' => true,
            'zabbix_poll_interval_minutes' => 5,
            'zabbix_problem_cache_ttl_minutes' => 5,
            'zabbix_problem_limit' => 100,
            'zabbix_exclude_suppressed_problems' => true,
            'default_close_delay_hours' => 4,
            'default_reopen_window_hours' => 24,

            'mail_transport' => 'smtp',
            'mail_smtp_host' => 'host',
            'mail_smtp_port' => 25,
            'mail_smtp_encryption' => 'tls',
            'mail_smtp_timeout_seconds' => 10,
            'mail_smtp_password' => '',
            'mail_smtp_password_clear' => false,
            'app_display_timezone' => 'Europe/Kyiv',
            'pagination_per_page_base' => 25,
            'ui_locale' => 'uk',
        ];
    }
}
