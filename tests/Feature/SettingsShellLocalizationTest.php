<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\User;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsShellLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_recursive_translation_integrity(): void
    {
        $en = require lang_path('en/settings.php');
        $uk = require lang_path('uk/settings.php');

        $this->assertIsArray($en['settings_page']);
        $this->assertIsArray($uk['settings_page']);

        $this->assertArrayKeysMatchRecursive($en['settings_page'], $uk['settings_page']);

        $this->assertNoEmptyValuesRecursive($en['settings_page'], 'en.settings_page');
        $this->assertNoEmptyValuesRecursive($uk['settings_page'], 'uk.settings_page');
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

        $component = Livewire::actingAs($user)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $sectionsByHeading = [];
        $tabsByLabel = [];

        $search = function ($components) use (&$search, &$sectionsByHeading, &$tabsByLabel): void {
            foreach ($components as $c) {
                if ($c instanceof Section) {
                    $sectionsByHeading[$c->getHeading()] = $c;
                }
                if ($c instanceof Tab) {
                    $tabsByLabel[$c->getLabel()] = $c;
                }
                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };

        $search($schema);

        $this->assertArrayHasKey('General', $tabsByLabel);
        $this->assertArrayHasKey('Statistics', $sectionsByHeading);
        $this->assertEquals('Configure how owner statistics are collected and retained.', $sectionsByHeading['Statistics']->getDescription());

        $component->assertSee('Save settings');
    }

    public function test_actual_filament_schema_in_ukrainian(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'ui_locale' => 'uk']);
        app()->setLocale('uk');

        $component = Livewire::actingAs($user)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $sectionsByHeading = [];
        $tabsByLabel = [];

        $search = function ($components) use (&$search, &$sectionsByHeading, &$tabsByLabel): void {
            foreach ($components as $c) {
                if ($c instanceof Section) {
                    $sectionsByHeading[$c->getHeading()] = $c;
                }
                if ($c instanceof Tab) {
                    $tabsByLabel[$c->getLabel()] = $c;
                }
                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };

        $search($schema);

        $this->assertArrayHasKey('Загальні', $tabsByLabel);
        $this->assertArrayHasKey('Основне', $tabsByLabel);
        $this->assertArrayHasKey('Параметри заявок Znuny', $tabsByLabel);
        $this->assertArrayHasKey('Статистика', $sectionsByHeading);
        $this->assertArrayHasKey('Відображення інтерфейсу', $sectionsByHeading);
        $this->assertEquals('Налаштуйте збір та зберігання статистики власників.', $sectionsByHeading['Статистика']->getDescription());

        $component->assertSee('Зберегти налаштування');
    }
}
