<?php

namespace Tests\Feature\Filament\Resources;

use App\Filament\Resources\ZabbixProblemFilters\Pages\ManageZabbixProblemFilters;
use App\Filament\Resources\ZabbixProblemFilters\ZabbixProblemFilterResource;
use App\Models\User;
use App\Models\ZabbixProblemFilter;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ZabbixProblemFilterLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_labels_are_localized()
    {
        app()->setLocale('en');
        $this->assertEquals('Ignore filter', ZabbixProblemFilterResource::getModelLabel());
        $this->assertEquals('Ignore filters', ZabbixProblemFilterResource::getPluralModelLabel());
        $this->assertEquals('Ignore filters', ZabbixProblemFilterResource::getNavigationLabel());

        app()->setLocale('uk');
        $this->assertEquals('Фільтр ігнорування', ZabbixProblemFilterResource::getModelLabel());
        $this->assertEquals('Фільтри ігнорування', ZabbixProblemFilterResource::getPluralModelLabel());
        $this->assertEquals('Фільтри ігнорування', ZabbixProblemFilterResource::getNavigationLabel());
    }

    public function test_page_titles_are_localized()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // EN
        app()->setLocale('en');
        $manage = Livewire::actingAs($admin)->test(ManageZabbixProblemFilters::class);
        $this->assertEquals('Ignore filters', $manage->instance()->getTitle());

        // UK
        app()->setLocale('uk');
        $manageUk = Livewire::actingAs($admin)->test(ManageZabbixProblemFilters::class);
        $this->assertEquals('Фільтри ігнорування', $manageUk->instance()->getTitle());
    }

    public function test_form_schema_is_localized()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $originalLocale = app()->getLocale();

        try {
            app()->setLocale('uk');
            $component = Livewire::actingAs($admin)->test(ManageZabbixProblemFilters::class);

            $form = ZabbixProblemFilterResource::form(
                Schema::make($component->instance())
            );

            $fields = [];

            $search = function ($components) use (&$search, &$fields) {
                foreach ($components as $c) {
                    if ($c instanceof Field && method_exists($c, 'getName') && $c->getName()) {
                        $fields[$c->getName()] = $c;
                    }

                    if (method_exists($c, 'getChildComponents')) {
                        $search($c->getChildComponents());
                    }
                }
            };
            $search($form->getComponents());

            $this->assertArrayHasKey('name', $fields);
            $this->assertEquals('Назва', $fields['name']->getLabel());

            $this->assertArrayHasKey('enabled', $fields);
            $this->assertEquals('Увімкнено', $fields['enabled']->getLabel());

            $this->assertArrayHasKey('pattern', $fields);
            $this->assertEquals('Шаблон', $fields['pattern']->getLabel());

            $this->assertArrayHasKey('description', $fields);
            $this->assertEquals('Опис', $fields['description']->getLabel());

            $this->assertArrayHasKey('field', $fields);
            $this->assertEquals('Поле', $fields['field']->getLabel());
            $this->assertInstanceOf(Select::class, $fields['field']);
            $fieldOptions = $fields['field']->getOptions();
            $this->assertArrayHasKey('name', $fieldOptions);
            $this->assertEquals('Назва проблеми', $fieldOptions['name']);
            $this->assertArrayHasKey('host', $fieldOptions);
            $this->assertEquals('Ім’я хоста', $fieldOptions['host']);

            $this->assertArrayHasKey('match_type', $fields);
            $this->assertEquals('Тип відповідності', $fields['match_type']->getLabel());
            $this->assertInstanceOf(Select::class, $fields['match_type']);
            $matchTypeOptions = $fields['match_type']->getOptions();
            $this->assertArrayHasKey('contains', $matchTypeOptions);
            $this->assertEquals('Містить', $matchTypeOptions['contains']);
            $this->assertArrayHasKey('regex', $matchTypeOptions);
            $this->assertEquals('Регулярний вираз', $matchTypeOptions['regex']);

            $this->assertArrayHasKey('case_sensitive', $fields);
            $this->assertEquals('Враховувати регістр', $fields['case_sensitive']->getLabel());

            // Test EN specific labels
            app()->setLocale('en');
            $componentEn = Livewire::actingAs($admin)->test(ManageZabbixProblemFilters::class);
            $formEn = ZabbixProblemFilterResource::form(
                Schema::make($componentEn->instance())
            );
            $fieldsEn = [];
            $searchEn = function ($components) use (&$searchEn, &$fieldsEn) {
                foreach ($components as $c) {
                    if ($c instanceof Field && method_exists($c, 'getName') && $c->getName()) {
                        $fieldsEn[$c->getName()] = $c;
                    }
                    if (method_exists($c, 'getChildComponents')) {
                        $searchEn($c->getChildComponents());
                    }
                }
            };
            $searchEn($formEn->getComponents());

            $this->assertArrayHasKey('case_sensitive', $fieldsEn);
            $this->assertEquals('Case sensitive', $fieldsEn['case_sensitive']->getLabel());
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_table_schema_is_localized()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        app()->setLocale('uk');
        $component = Livewire::actingAs($admin)->test(ManageZabbixProblemFilters::class);

        $table = $component->instance()->getTable('table');
        $columns = $table->getColumns();

        $this->assertArrayHasKey('enabled', $columns);
        $this->assertEquals('Увімкнено', $columns['enabled']->getLabel());

        $this->assertArrayHasKey('name', $columns);
        $this->assertEquals('Назва', $columns['name']->getLabel());

        $this->assertArrayHasKey('field', $columns);
        $this->assertEquals('Поле', $columns['field']->getLabel());
        $this->assertEquals('Назва проблеми', $columns['field']->formatState('name'));
        $this->assertEquals('Ім’я хоста', $columns['field']->formatState('host'));
        $this->assertEquals('unknown_field', $columns['field']->formatState('unknown_field'));

        $this->assertArrayHasKey('match_type', $columns);
        $this->assertEquals('Тип відповідності', $columns['match_type']->getLabel());
        $this->assertEquals('Містить', $columns['match_type']->formatState('contains'));
        $this->assertEquals('Регулярний вираз', $columns['match_type']->formatState('regex'));
        $this->assertEquals('unknown_type', $columns['match_type']->formatState('unknown_type'));

        $this->assertArrayHasKey('pattern', $columns);
        $this->assertEquals('Шаблон', $columns['pattern']->getLabel());

        $this->assertArrayHasKey('updated_at', $columns);
        $this->assertEquals('Оновлено', $columns['updated_at']->getLabel());
    }

    public function test_creating_editing_and_deleting_filter_retains_raw_data()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        app()->setLocale('uk');

        // Test create
        Livewire::actingAs($admin)
            ->test(ManageZabbixProblemFilters::class)
            ->callAction('create', [
                'name' => 'Raw Name Test',
                'field' => 'name',
                'match_type' => 'regex',
                'pattern' => '/^raw_pattern$/',
                'description' => 'Test description',
                'enabled' => true,
                'case_sensitive' => true,
            ])
            ->assertHasNoActionErrors();

        $filter = ZabbixProblemFilter::where('name', 'Raw Name Test')->first();
        $this->assertNotNull($filter);
        $this->assertEquals('Raw Name Test', $filter->name);
        $this->assertEquals('name', $filter->field);
        $this->assertEquals('regex', $filter->match_type);
        $this->assertEquals('/^raw_pattern$/', $filter->pattern);
        $this->assertEquals('Test description', $filter->description);
        $this->assertTrue($filter->enabled);
        $this->assertTrue($filter->case_sensitive);

        // Test edit
        Livewire::actingAs($admin)
            ->test(ManageZabbixProblemFilters::class)
            ->callTableAction('edit', $filter, [
                'name' => 'Updated Raw Name Test',
                'field' => 'host',
                'match_type' => 'contains',
                'pattern' => 'updated_raw_pattern',
                'description' => 'Updated description',
                'enabled' => false,
                'case_sensitive' => false,
            ])
            ->assertHasNoTableActionErrors();

        $filter->refresh();
        $this->assertEquals('Updated Raw Name Test', $filter->name);
        $this->assertEquals('host', $filter->field);
        $this->assertEquals('contains', $filter->match_type);
        $this->assertEquals('updated_raw_pattern', $filter->pattern);
        $this->assertEquals('Updated description', $filter->description);
        $this->assertFalse($filter->enabled);
        $this->assertFalse($filter->case_sensitive);

        // Test delete
        Livewire::actingAs($admin)
            ->test(ManageZabbixProblemFilters::class)
            ->callTableAction('delete', $filter)
            ->assertHasNoTableActionErrors();

        $this->assertNull(ZabbixProblemFilter::where('name', 'Updated Raw Name Test')->first());
    }
}
