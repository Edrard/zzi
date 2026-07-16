<?php

namespace Tests\Feature;

use App\Filament\Pages\MySettings;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MySettingsLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_recursive_translation_integrity(): void
    {
        $en = require lang_path('en/settings.php');
        $uk = require lang_path('uk/settings.php');

        $this->assertIsArray($en['my_settings']);
        $this->assertIsArray($uk['my_settings']);

        $this->assertArrayKeysMatchRecursive($en['my_settings'], $uk['my_settings']);

        $this->assertNoEmptyValuesRecursive($en['my_settings'], 'en.my_settings');
        $this->assertNoEmptyValuesRecursive($uk['my_settings'], 'uk.my_settings');
    }

    private function assertArrayKeysMatchRecursive(array $expected, array $actual, string $path = ''): void
    {
        $expectedKeys = array_keys($expected);
        $actualKeys = array_keys($actual);

        sort($expectedKeys);
        sort($actualKeys);

        $this->assertEquals($expectedKeys, $actualKeys, "Keys do not match at path: {$path}");

        foreach ($expected as $key => $value) {
            if (is_array($value)) {
                $this->assertIsArray($actual[$key], "Expected array at path: {$path}.{$key}");
                $this->assertArrayKeysMatchRecursive($value, $actual[$key], "{$path}.{$key}");
            } else {
                $this->assertIsNotArray($actual[$key], "Expected scalar at path: {$path}.{$key}");
            }
        }
    }

    private function assertNoEmptyValuesRecursive(array $array, string $path = ''): void
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $this->assertNoEmptyValuesRecursive($value, "{$path}.{$key}");
            } else {
                $this->assertNotEmpty($value, "Empty value found at path: {$path}.{$key}");
                $this->assertIsString($value);
            }
        }
    }

    public function test_actual_filament_schema_in_english(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'ui_locale' => 'en']);
        app()->setLocale('en');

        $component = Livewire::actingAs($user)->test(MySettings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $componentsByName = [];
        $sectionsByHeading = [];

        $search = function ($components) use (&$search, &$componentsByName, &$sectionsByHeading): void {
            foreach ($components as $c) {
                if (method_exists($c, 'getName') && $c->getName()) {
                    $componentsByName[$c->getName()] = $c;
                }
                if ($c instanceof Section) {
                    $sectionsByHeading[$c->getHeading()] = $c;
                }
                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };

        $search($schema);

        $this->assertArrayHasKey('Profile / Password', $sectionsByHeading);
        $this->assertEquals('Update your account password. Leave blank if you do not wish to change it.', $sectionsByHeading['Profile / Password']->getDescription());

        $this->assertArrayHasKey('current_password', $componentsByName);
        $this->assertEquals('Current password', $componentsByName['current_password']->getLabel());

        $this->assertArrayHasKey('new_password', $componentsByName);
        $this->assertEquals('New password', $componentsByName['new_password']->getLabel());

        $this->assertArrayHasKey('new_password_confirmation', $componentsByName);
        $this->assertEquals('Confirm new password', $componentsByName['new_password_confirmation']->getLabel());

        $this->assertArrayHasKey('Personalization', $sectionsByHeading);
        $this->assertEquals('Customize your interface.', $sectionsByHeading['Personalization']->getDescription());

        $this->assertArrayHasKey('ui_locale', $componentsByName);
        $this->assertEquals('Interface language', $componentsByName['ui_locale']->getLabel());
        $component->assertSee('Choose a personal interface language or use the system default.');

        $this->assertArrayHasKey('Startup / Default page', $sectionsByHeading);
        $this->assertEquals('Choose which page you land on after logging in.', $sectionsByHeading['Startup / Default page']->getDescription());

        $this->assertArrayHasKey('default_landing_page', $componentsByName);
        $this->assertEquals('Default landing page', $componentsByName['default_landing_page']->getLabel());

        $this->assertArrayHasKey('Admin UI Preferences', $sectionsByHeading);
        $this->assertEquals('Toggle visibility of diagnostic panels.', $sectionsByHeading['Admin UI Preferences']->getDescription());

        $this->assertArrayHasKey('show_current_problems_status_panel', $componentsByName);
        $this->assertEquals('Show Current Problems polling status panel', $componentsByName['show_current_problems_status_panel']->getLabel());

        $component->assertSee('Save settings');
    }

    public function test_actual_filament_schema_in_ukrainian(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'ui_locale' => 'uk']);
        app()->setLocale('uk');

        $component = Livewire::actingAs($user)->test(MySettings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $componentsByName = [];
        $sectionsByHeading = [];

        $search = function ($components) use (&$search, &$componentsByName, &$sectionsByHeading): void {
            foreach ($components as $c) {
                if (method_exists($c, 'getName') && $c->getName()) {
                    $componentsByName[$c->getName()] = $c;
                }
                if ($c instanceof Section) {
                    $sectionsByHeading[$c->getHeading()] = $c;
                }
                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };

        $search($schema);

        $this->assertArrayHasKey('Профіль і пароль', $sectionsByHeading);
        $this->assertEquals('Змініть пароль облікового запису. Залиште поля порожніми, якщо не плануєте змінювати пароль.', $sectionsByHeading['Профіль і пароль']->getDescription());

        $this->assertArrayHasKey('current_password', $componentsByName);
        $this->assertEquals('Поточний пароль', $componentsByName['current_password']->getLabel());

        $this->assertArrayHasKey('new_password', $componentsByName);
        $this->assertEquals('Новий пароль', $componentsByName['new_password']->getLabel());

        $this->assertArrayHasKey('new_password_confirmation', $componentsByName);
        $this->assertEquals('Підтвердження нового пароля', $componentsByName['new_password_confirmation']->getLabel());

        $this->assertArrayHasKey('Персоналізація', $sectionsByHeading);
        $this->assertEquals('Налаштуйте інтерфейс.', $sectionsByHeading['Персоналізація']->getDescription());

        $this->assertArrayHasKey('ui_locale', $componentsByName);
        $this->assertEquals('Мова інтерфейсу', $componentsByName['ui_locale']->getLabel());
        $component->assertSee('Виберіть особисту мову інтерфейсу або використовуйте системну за замовчуванням.');

        $this->assertArrayHasKey('Початкова сторінка', $sectionsByHeading);
        $this->assertEquals('Виберіть сторінку, яка відкриватиметься після входу.', $sectionsByHeading['Початкова сторінка']->getDescription());

        $this->assertArrayHasKey('default_landing_page', $componentsByName);
        $this->assertEquals('Початкова сторінка за замовчуванням', $componentsByName['default_landing_page']->getLabel());

        $this->assertArrayHasKey('Налаштування інтерфейсу адміністратора', $sectionsByHeading);
        $this->assertEquals('Керуйте відображенням діагностичних панелей.', $sectionsByHeading['Налаштування інтерфейсу адміністратора']->getDescription());

        $this->assertArrayHasKey('show_current_problems_status_panel', $componentsByName);
        $this->assertEquals('Показувати панель статусу опитування поточних проблем', $componentsByName['show_current_problems_status_panel']->getLabel());

        $component->assertSee('Зберегти налаштування');
    }

    public function test_interface_language_options_and_stored_values_in_english(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'ui_locale' => 'en']);
        app()->setLocale('en');

        $component = Livewire::actingAs($user)->test(MySettings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        /** @var Select|null $uiLocaleField */
        $uiLocaleField = null;

        $search = function ($components) use (&$search, &$uiLocaleField): void {
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
        $this->assertCount(3, $options);
        $this->assertArrayHasKey('__system__', $options);
        $this->assertArrayHasKey('en', $options);
        $this->assertArrayHasKey('uk', $options);

        $this->assertEquals('Use system default', $options['__system__']);
        $this->assertEquals('English', $options['en']);
        $this->assertEquals('Українська', $options['uk']);

        $this->assertEquals(['__system__', 'uk', 'en'], array_keys($options));
    }

    public function test_interface_language_options_and_stored_values_in_ukrainian(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'ui_locale' => 'uk']);
        app()->setLocale('uk');

        $component = Livewire::actingAs($user)->test(MySettings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        /** @var Select|null $uiLocaleField */
        $uiLocaleField = null;

        $search = function ($components) use (&$search, &$uiLocaleField): void {
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
        $this->assertCount(3, $options);
        $this->assertArrayHasKey('__system__', $options);
        $this->assertArrayHasKey('en', $options);
        $this->assertArrayHasKey('uk', $options);

        $this->assertEquals('Використовувати системну за замовчуванням', $options['__system__']);
        $this->assertEquals('English', $options['en']);
        $this->assertEquals('Українська', $options['uk']);

        $this->assertEquals(['__system__', 'uk', 'en'], array_keys($options));
    }

    public function test_save_notification_in_english(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'ui_locale' => 'en']);
        app()->setLocale('en');

        Livewire::actingAs($user)
            ->test(MySettings::class)
            ->fillForm([
                'default_landing_page' => 'current-zabbix-problems',
                'ui_locale' => 'en',
                'show_current_problems_status_panel' => true,
                'show_znuny_closed_ticket_status_panel' => true,
                'show_scheduled_tasks_status_panel' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Settings saved successfully');
    }

    public function test_save_notification_in_ukrainian(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'ui_locale' => 'uk']);
        app()->setLocale('uk');

        Livewire::actingAs($user)
            ->test(MySettings::class)
            ->fillForm([
                'default_landing_page' => 'current-zabbix-problems',
                'ui_locale' => 'uk',
                'show_current_problems_status_panel' => true,
                'show_znuny_closed_ticket_status_panel' => true,
                'show_scheduled_tasks_status_panel' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Налаштування успішно збережено');
    }

    public function test_raw_visible_string_source_audit(): void
    {
        $content = file_get_contents(app_path('Filament/Pages/MySettings.php'));

        $pattern = '/\->(?:heading|label|description|helperText|placeholder|title)\(\s*[\'"][^\'"]+[\'"]\s*\)/';

        $this->assertDoesNotMatchRegularExpression($pattern, $content, 'Found raw string passed to visible text method');

        $bladeContent = file_get_contents(resource_path('views/filament/pages/my-settings.blade.php'));
        $this->assertStringNotContainsString('Save settings', $bladeContent, 'Found raw Save settings string in blade file');
    }
}
