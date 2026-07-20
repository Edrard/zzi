<?php

namespace Tests\Feature\Filament\Resources;

use App\Filament\Resources\AttentionFilters\AttentionFilterResource;
use App\Filament\Resources\AttentionFilters\Pages\ManageAttentionFilters;
use App\Models\AttentionFilter;
use App\Models\User;
use Filament\Forms\Components\Field;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AttentionFilterLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_labels_are_localized()
    {
        app()->setLocale('en');
        $this->assertEquals('Attention filter', AttentionFilterResource::getModelLabel());
        $this->assertEquals('Attention filters', AttentionFilterResource::getPluralModelLabel());
        $this->assertEquals('Attention filters', AttentionFilterResource::getNavigationLabel());

        app()->setLocale('uk');
        $this->assertEquals('Фільтр уваги', AttentionFilterResource::getModelLabel());
        $this->assertEquals('Фільтри уваги', AttentionFilterResource::getPluralModelLabel());
        $this->assertEquals('Фільтри уваги', AttentionFilterResource::getNavigationLabel());
    }

    public function test_page_titles_are_localized()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // EN
        app()->setLocale('en');
        $manage = Livewire::actingAs($admin)->test(ManageAttentionFilters::class);
        $this->assertEquals('Attention filters', $manage->instance()->getTitle());

        // UK
        app()->setLocale('uk');
        $manageUk = Livewire::actingAs($admin)->test(ManageAttentionFilters::class);
        $this->assertEquals('Фільтри уваги', $manageUk->instance()->getTitle());
    }

    public function test_form_schema_is_localized()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        app()->setLocale('uk');
        $component = Livewire::actingAs($admin)->test(ManageAttentionFilters::class);

        $form = AttentionFilterResource::form(
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
    }

    public function test_table_schema_is_localized()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        app()->setLocale('uk');
        $component = Livewire::actingAs($admin)->test(ManageAttentionFilters::class);

        $table = $component->instance()->getTable('table');
        $columns = $table->getColumns();

        $this->assertArrayHasKey('enabled', $columns);
        $this->assertEquals('Увімкнено', $columns['enabled']->getLabel());

        $this->assertArrayHasKey('name', $columns);
        $this->assertEquals('Назва', $columns['name']->getLabel());

        $this->assertArrayHasKey('pattern', $columns);
        $this->assertEquals('Шаблон', $columns['pattern']->getLabel());

        $this->assertArrayHasKey('updated_at', $columns);
        $this->assertEquals('Оновлено', $columns['updated_at']->getLabel());
    }

    public function test_creating_and_editing_filter_retains_raw_data()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        app()->setLocale('uk');

        // Test create
        $component = Livewire::actingAs($admin)
            ->test(ManageAttentionFilters::class)
            ->callAction('create', [
                'name' => 'Raw Name Test',
                'pattern' => '/^raw_pattern$/',
                'description' => 'Test description',
                'enabled' => true,
            ]);

        $component->assertHasNoActionErrors();

        $filter = AttentionFilter::where('name', 'Raw Name Test')->first();
        $this->assertNotNull($filter);
        $this->assertEquals('Raw Name Test', $filter->name);
        $this->assertEquals('/^raw_pattern$/', $filter->pattern);
        $this->assertEquals('Test description', $filter->description);
        $this->assertEquals(true, (bool) $filter->enabled);

        // Test edit
        Livewire::actingAs($admin)
            ->test(ManageAttentionFilters::class)
            ->callTableAction('edit', $filter, [
                'name' => 'Updated Raw Name Test',
                'pattern' => '/^updated_raw_pattern$/',
                'description' => 'Updated description',
                'enabled' => false,
            ])
            ->assertHasNoTableActionErrors();

        $filter->refresh();
        $this->assertEquals('Updated Raw Name Test', $filter->name);
        $this->assertEquals('/^updated_raw_pattern$/', $filter->pattern);
        $this->assertEquals('Updated description', $filter->description);
        $this->assertEquals(false, (bool) $filter->enabled);

        // Test delete
        Livewire::actingAs($admin)
            ->test(ManageAttentionFilters::class)
            ->callTableAction('delete', $filter)
            ->assertHasNoTableActionErrors();

        $this->assertNull(AttentionFilter::where('name', 'Updated Raw Name Test')->first());
    }
}
