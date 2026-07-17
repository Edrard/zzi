<?php

namespace Tests\Feature\Filament\Resources;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Filament\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class AuditLogResourceLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_labels_are_localized()
    {
        $originalLocale = app()->getLocale();
        try {
            app()->setLocale('en');
            $this->assertEquals('Audit Log', AuditLogResource::getModelLabel());
            $this->assertEquals('Audit Logs', AuditLogResource::getPluralModelLabel());
            $this->assertEquals('Audit Log', AuditLogResource::getNavigationLabel());
            $this->assertEquals(UserResource::getNavigationGroup(), AuditLogResource::getNavigationGroup());

            app()->setLocale('uk');
            $this->assertEquals('Журнал аудиту', AuditLogResource::getModelLabel());
            $this->assertEquals('Журнали аудиту', AuditLogResource::getPluralModelLabel());
            $this->assertEquals('Журнал аудиту', AuditLogResource::getNavigationLabel());
            $this->assertEquals(UserResource::getNavigationGroup(), AuditLogResource::getNavigationGroup());
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_page_titles_are_localized()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $log = AuditLog::create([
            'action' => 'test.action',
        ]);

        $originalLocale = app()->getLocale();
        try {
            // EN
            app()->setLocale('en');
            $list = Livewire::actingAs($admin)->test(ListAuditLogs::class);
            $this->assertEquals('Audit Logs', $list->instance()->getTitle());

            $view = Livewire::actingAs($admin)->test(ViewAuditLog::class, ['record' => $log->id]);
            $this->assertEquals('Audit Log', $view->instance()->getTitle());

            // UK
            app()->setLocale('uk');
            $listUk = Livewire::actingAs($admin)->test(ListAuditLogs::class);
            $this->assertEquals('Журнали аудиту', $listUk->instance()->getTitle());

            $viewUk = Livewire::actingAs($admin)->test(ViewAuditLog::class, ['record' => $log->id]);
            $this->assertEquals('Журнал аудиту', $viewUk->instance()->getTitle());
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_infolist_schema_is_localized()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $log = AuditLog::create([
            'action' => 'settings.updated',
            'entity_type' => 'App\\Models\\Setting',
        ]);

        $originalLocale = app()->getLocale();
        try {
            app()->setLocale('uk');
            $component = Livewire::actingAs($admin)->test(ViewAuditLog::class, ['record' => $log->id]);

            $schema = $component->instance()->getSchema('infolist');

            $section = null;
            $entries = [];

            $search = function ($components) use (&$search, &$section, &$entries) {
                foreach ($components as $c) {
                    if ($c instanceof Section && $c->getHeading() === 'Деталі журналу') {
                        $section = $c;
                    }

                    if ($c instanceof Entry && method_exists($c, 'getName') && $c->getName()) {
                        $entries[$c->getName()] = $c;
                    }

                    if (method_exists($c, 'getChildComponents')) {
                        $search($c->getChildComponents());
                    }
                }
            };
            $search($schema->getComponents());

            $this->assertNotNull($section);
            $this->assertEquals('Деталі журналу', $section->getHeading());

            $this->assertArrayHasKey('created_at', $entries);
            $this->assertEquals('Часова мітка', $entries['created_at']->getLabel());

            $this->assertArrayHasKey('user.name', $entries);
            $this->assertEquals('Користувач', $entries['user.name']->getLabel());

            $this->assertArrayHasKey('action', $entries);
            $this->assertEquals('Дія', $entries['action']->getLabel());

            $this->assertArrayHasKey('entity_type', $entries);
            $this->assertEquals('Тип сутності', $entries['entity_type']->getLabel());

            $this->assertArrayHasKey('entity_id', $entries);
            $this->assertEquals('ID сутності', $entries['entity_id']->getLabel());

            $this->assertArrayHasKey('ip_address', $entries);
            $this->assertEquals('IP-адреса', $entries['ip_address']->getLabel());
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_table_schema_is_localized()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $originalLocale = app()->getLocale();
        try {
            app()->setLocale('uk');
            $component = Livewire::actingAs($admin)->test(ListAuditLogs::class);

            $table = $component->instance()->getTable('table');
            $columns = $table->getColumns();

            $this->assertArrayHasKey('created_at', $columns);
            $this->assertEquals('Часова мітка', $columns['created_at']->getLabel());

            $this->assertArrayHasKey('user.name', $columns);
            $this->assertEquals('Користувач', $columns['user.name']->getLabel());

            $this->assertArrayHasKey('action', $columns);
            $this->assertEquals('Дія', $columns['action']->getLabel());

            $this->assertArrayHasKey('entity_type', $columns);
            $this->assertEquals('Тип сутності', $columns['entity_type']->getLabel());

            $this->assertArrayHasKey('entity_id', $columns);
            $this->assertEquals('ID сутності', $columns['entity_id']->getLabel());

            $this->assertArrayHasKey('ip_address', $columns);
            $this->assertEquals('IP-адреса', $columns['ip_address']->getLabel());
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_raw_values_are_preserved_in_database_and_translate_correctly()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $log = AuditLog::create([
            'action' => 'settings.updated',
            'entity_type' => 'App\\Models\\ZabbixProblemFilter',
            'created_at' => Carbon::create(2026, 7, 17, 14, 10, 45, 'UTC'),
        ]);

        $this->assertEquals('settings.updated', $log->action);
        $this->assertEquals('App\\Models\\ZabbixProblemFilter', $log->entity_type);

        $originalLocale = app()->getLocale();
        try {
            app()->setLocale('uk');

            // 1. Test shared presentation methods directly
            $expectedActionLabel = __('audit_logs.actions.settings.updated');
            $this->assertNotEquals('settings.updated', $expectedActionLabel);
            $this->assertEquals($expectedActionLabel, AuditLogResource::actionLabel('settings.updated'));

            $expectedEntityLabel = __('audit_logs.entity_types.zabbix_problem_filter');
            $this->assertNotEquals('zabbix_problem_filter', $expectedEntityLabel);
            $this->assertEquals($expectedEntityLabel, AuditLogResource::entityTypeLabel('App\\Models\\ZabbixProblemFilter'));

            $expectedSystemFallback = __('audit_logs.entity_types.system');
            $this->assertEquals($expectedSystemFallback, AuditLogResource::actorLabel(null));
            $this->assertEquals($expectedSystemFallback, AuditLogResource::actorLabel(''));
            $this->assertEquals('John Doe', AuditLogResource::actorLabel('John Doe'));

            $this->assertNull(AuditLogResource::entityTypeLabel(null));
            $this->assertEquals('', AuditLogResource::entityTypeLabel(''));

            // Unknown fallback
            $this->assertEquals('unknown.action', AuditLogResource::actionLabel('unknown.action'));
            $this->assertEquals('Unknown\\Entity', AuditLogResource::entityTypeLabel('Unknown\\Entity'));

            // 2. Test table delegates correctly
            $component = Livewire::actingAs($admin)->test(ListAuditLogs::class);
            $table = $component->instance()->getTable('table');
            $columns = $table->getColumns();

            $actionCol = $columns['action'];
            $this->assertEquals($expectedActionLabel, $actionCol->formatState($log->action));

            $entityCol = $columns['entity_type'];
            $this->assertEquals($expectedEntityLabel, $entityCol->formatState($log->entity_type));

            $dateCol = $columns['created_at'];
            $this->assertStringContainsString('17 липня 2026', $dateCol->formatState($log->created_at));
        } finally {
            app()->setLocale($originalLocale);
        }
    }
}
