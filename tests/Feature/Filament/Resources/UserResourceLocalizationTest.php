<?php

namespace Tests\Feature\Filament\Resources;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserResourceLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_labels_are_localized()
    {
        app()->setLocale('en');
        $this->assertEquals('User', UserResource::getModelLabel());
        $this->assertEquals('Users', UserResource::getPluralModelLabel());

        app()->setLocale('uk');
        $this->assertEquals('Користувач', UserResource::getModelLabel());
        $this->assertEquals('Користувачі', UserResource::getPluralModelLabel());
    }

    public function test_page_titles_are_localized()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // EN
        app()->setLocale('en');
        $list = Livewire::actingAs($admin)->test(ListUsers::class);
        $this->assertEquals('Users', $list->instance()->getTitle());

        $create = Livewire::actingAs($admin)->test(CreateUser::class);
        $this->assertEquals('Create user', $create->instance()->getTitle());

        $edit = Livewire::actingAs($admin)->test(EditUser::class, ['record' => $admin->id]);
        $this->assertEquals('Edit user', $edit->instance()->getTitle());

        // UK
        app()->setLocale('uk');
        $listUk = Livewire::actingAs($admin)->test(ListUsers::class);
        $this->assertEquals('Користувачі', $listUk->instance()->getTitle());

        $createUk = Livewire::actingAs($admin)->test(CreateUser::class);
        $this->assertEquals('Створити користувача', $createUk->instance()->getTitle());

        $editUk = Livewire::actingAs($admin)->test(EditUser::class, ['record' => $admin->id]);
        $this->assertEquals('Редагувати користувача', $editUk->instance()->getTitle());
    }

    public function test_form_schema_is_localized()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        app()->setLocale('uk');
        $component = Livewire::actingAs($admin)->test(CreateUser::class);

        $form = $component->instance()->getSchema('form');

        $section = null;
        $fields = [];

        $search = function ($components) use (&$search, &$section, &$fields) {
            foreach ($components as $c) {
                if ($c instanceof Section && $c->getHeading() === 'Дані користувача') {
                    $section = $c;
                }

                if ($c instanceof Field && method_exists($c, 'getName') && $c->getName()) {
                    $fields[$c->getName()] = $c;
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents());
                }
            }
        };
        $search($form->getComponents());

        $this->assertNotNull($section);
        $this->assertEquals('Дані користувача', $section->getHeading());
        $this->assertEquals('Керування іменем, роллю, статусом і паролем користувача.', $section->getDescription());

        $this->assertArrayHasKey('name', $fields);
        $this->assertEquals('Ім’я', $fields['name']->getLabel());

        $this->assertArrayHasKey('email', $fields);
        $this->assertEquals('Електронна пошта', $fields['email']->getLabel());

        $this->assertArrayHasKey('role', $fields);
        $this->assertEquals('Роль', $fields['role']->getLabel());
        $this->assertEquals('Адміністратор', $fields['role']->getOptions()['admin']);
        $this->assertEquals('Оператор', $fields['role']->getOptions()['operator']);
        $this->assertEquals('Переглядач', $fields['role']->getOptions()['viewer']);

        $this->assertArrayHasKey('is_active', $fields);
        $this->assertEquals('Активний', $fields['is_active']->getLabel());

        $this->assertArrayHasKey('password', $fields);
        $this->assertEquals('Пароль', $fields['password']->getLabel());

        $this->assertArrayHasKey('password_confirmation', $fields);
        $this->assertEquals('Підтвердження пароля', $fields['password_confirmation']->getLabel());
    }

    public function test_table_schema_is_localized()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        app()->setLocale('uk');
        $component = Livewire::actingAs($admin)->test(ListUsers::class);

        $table = $component->instance()->getTable('table');
        $columns = $table->getColumns();

        $this->assertArrayHasKey('name', $columns);
        $this->assertEquals('Ім’я', $columns['name']->getLabel());

        $this->assertArrayHasKey('email', $columns);
        $this->assertEquals('Електронна пошта', $columns['email']->getLabel());

        $this->assertArrayHasKey('role', $columns);
        $this->assertEquals('Роль', $columns['role']->getLabel());

        // Format state manually since it relies on a closure for the badge value formatting
        $roleCol = $columns['role'];
        $formattedAdmin = $roleCol->formatState('admin');
        $this->assertEquals('Адміністратор', $formattedAdmin);

        $this->assertArrayHasKey('is_active', $columns);
        $this->assertEquals('Активний', $columns['is_active']->getLabel());

        $this->assertArrayHasKey('created_at', $columns);
        $this->assertEquals('Створено', $columns['created_at']->getLabel());

        $this->assertArrayHasKey('updated_at', $columns);
        $this->assertEquals('Оновлено', $columns['updated_at']->getLabel());
    }

    public function test_editing_user_with_ukrainian_locale_retains_raw_data_and_passwords()
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'name' => 'Old Name',
            'email' => 'old@example.com',
            'password' => Hash::make('old_password'),
            'is_active' => true,
        ]);

        $target = User::factory()->create([
            'role' => 'operator',
            'name' => 'Operator',
            'email' => 'operator@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => false,
        ]);

        app()->setLocale('uk');

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $target->id])
            ->fillForm([
                'name' => 'New Operator',
                'email' => 'operator_new@example.com',
                'role' => 'viewer',
                'is_active' => true,
                'password' => '',
                'password_confirmation' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $target->refresh();
        $this->assertEquals('New Operator', $target->name);
        $this->assertEquals('operator_new@example.com', $target->email);
        $this->assertEquals('viewer', $target->role);
        $this->assertTrue($target->is_active);

        $this->assertTrue(Hash::check('secret123', $target->password));
    }
}
