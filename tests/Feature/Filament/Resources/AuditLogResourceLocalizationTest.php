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
            $this->assertEquals('Audit Logs', AuditLogResource::getBreadcrumb());
            $this->assertEquals(UserResource::getNavigationGroup(), AuditLogResource::getNavigationGroup());

            app()->setLocale('uk');
            $this->assertEquals('Журнал аудиту', AuditLogResource::getModelLabel());
            $this->assertEquals('Журнали аудиту', AuditLogResource::getPluralModelLabel());
            $this->assertEquals('Журнал аудиту', AuditLogResource::getNavigationLabel());
            $this->assertEquals('Журнали аудиту', AuditLogResource::getBreadcrumb());
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

        $logNew = AuditLog::create([
            'action' => 'znuny.standalone_ticket.created',
            'entity_type' => 'znuny_standalone_ticket',
            'created_at' => Carbon::create(2026, 7, 17, 15, 10, 45, 'UTC'),
        ]);

        $this->assertEquals('settings.updated', $log->action);
        $this->assertEquals('App\\Models\\ZabbixProblemFilter', $log->entity_type);
        $this->assertEquals('znuny.standalone_ticket.created', $logNew->action);
        $this->assertEquals('znuny_standalone_ticket', $logNew->entity_type);

        $originalLocale = app()->getLocale();
        try {
            // 1. Test shared presentation methods directly via loop
            $expectedAddedActions = [
                'cleanup.failed' => ['en' => 'Cleanup Failed', 'uk' => 'Помилка очищення'],
                'scheduled_znuny_attempt_manual_retry_created' => ['en' => 'Manual retry created', 'uk' => 'Створено повторну спробу вручну'],
                'scheduled_znuny_attempt_manually_linked' => ['en' => 'Creation attempt manually linked', 'uk' => 'Спробу створення вручну пов’язано зі зверненням'],
                'scheduled_znuny_run_manually_closed' => ['en' => 'Run manually closed', 'uk' => 'Запуск закрито вручну'],
                'scheduled_znuny_run_retry_created' => ['en' => 'Retry run created', 'uk' => 'Створено повторний запуск'],
                'scheduled_znuny_run_uncertain' => ['en' => 'Run uncertain', 'uk' => 'Невизначений запуск'],
                'settings.cache.clear' => ['en' => 'Settings cache cleared', 'uk' => 'Кеш налаштувань очищено'],
                'settings.znuny_agent_cache.clear' => ['en' => 'Znuny agent cache cleared', 'uk' => 'Кеш агентів Znuny очищено'],
                'settings.znuny_queue_cache.clear' => ['en' => 'Znuny queue cache cleared', 'uk' => 'Кеш черг Znuny очищено'],
                'settings.znuny_lookup_cache.clear' => ['en' => 'Znuny lookup cache cleared', 'uk' => 'Кеш пошуку Znuny очищено'],
                'settings.znuny_ticket_article_cache.clear' => ['en' => 'Znuny ticket article cache cleared', 'uk' => 'Кеш статей заявок Znuny очищено'],
                'user.unlocked' => ['en' => 'User Unlocked', 'uk' => 'Користувача розблоковано'],
                'zabbix.connection_failed' => ['en' => 'Zabbix Connection Failed', 'uk' => 'Помилка підключення до Zabbix'],
                'zabbix.connection_tested' => ['en' => 'Zabbix Connection Tested', 'uk' => 'Перевірено підключення до Zabbix'],
                'znuny.connection_failed' => ['en' => 'Znuny Connection Failed', 'uk' => 'Помилка підключення до Znuny'],
                'znuny.connection_tested' => ['en' => 'Znuny Connection Tested', 'uk' => 'Перевірено підключення до Znuny'],
                'znuny.standalone_ticket.created' => ['en' => 'Standalone ticket created', 'uk' => 'Окрему заявку Znuny створено'],
                'znuny.standalone_ticket.failed' => ['en' => 'Standalone ticket creation failed', 'uk' => 'Помилка створення окремої заявки Znuny'],
                'znuny.standalone_ticket.failed_validation' => ['en' => 'Standalone ticket validation failed', 'uk' => 'Помилка перевірки окремої заявки Znuny'],
                'znuny.customer_user.created' => ['en' => 'Znuny customer user created', 'uk' => 'Користувача клієнта Znuny створено'],
                'znuny.customer_user.create_failed' => ['en' => 'Znuny customer user creation failed', 'uk' => 'Не вдалося створити користувача клієнта Znuny'],
            ];

            $expectedAddedEntities = [
                'znuny_standalone_ticket' => ['en' => 'Znuny Standalone Ticket', 'uk' => 'Окрема заявка Znuny'],
                'ScheduledZnunyTaskRun' => ['en' => 'Scheduled Znuny task run', 'uk' => 'Запуск запланованого завдання Znuny'],
                'ZnunyTicketCreationAttempt' => ['en' => 'Znuny ticket creation attempt', 'uk' => 'Спроба створення звернення Znuny'],
                'znuny_customer_user' => ['en' => 'Znuny customer user', 'uk' => 'Користувач клієнта Znuny'],
            ];

            foreach (['en', 'uk'] as $locale) {
                app()->setLocale($locale);

                foreach ($expectedAddedActions as $action => $labels) {
                    $this->assertEquals($labels[$locale], AuditLogResource::actionLabel($action));
                }

                foreach ($expectedAddedEntities as $entity => $labels) {
                    $this->assertEquals($labels[$locale], AuditLogResource::entityTypeLabel($entity));
                }

                $this->assertEquals('unknown.action', AuditLogResource::actionLabel('unknown.action'));
                $this->assertEquals('Unknown\\Entity', AuditLogResource::entityTypeLabel('Unknown\\Entity'));

                $expectedSystemFallback = __('audit_logs.entity_types.system');
                $this->assertEquals($expectedSystemFallback, AuditLogResource::actorLabel(null));
                $this->assertEquals($expectedSystemFallback, AuditLogResource::actorLabel(''));
                $this->assertEquals('John Doe', AuditLogResource::actorLabel('John Doe'));

                $this->assertNull(AuditLogResource::entityTypeLabel(null));
                $this->assertEquals('', AuditLogResource::entityTypeLabel(''));
            }

            // Test specific original labels in UK
            app()->setLocale('uk');
            $expectedActionLabel = __('audit_logs.actions.settings.updated');
            $this->assertNotEquals('settings.updated', $expectedActionLabel);
            $this->assertEquals($expectedActionLabel, AuditLogResource::actionLabel('settings.updated'));

            $expectedEntityLabel = __('audit_logs.entity_types.zabbix_problem_filter');
            $this->assertNotEquals('zabbix_problem_filter', $expectedEntityLabel);
            $this->assertEquals($expectedEntityLabel, AuditLogResource::entityTypeLabel('App\\Models\\ZabbixProblemFilter'));

            // 2. Test table delegates correctly
            $component = Livewire::actingAs($admin)->test(ListAuditLogs::class);
            $table = $component->instance()->getTable('table');
            $columns = $table->getColumns();

            $actionCol = $columns['action'];
            $this->assertEquals($expectedActionLabel, $actionCol->formatState($log->action));
            $this->assertEquals($expectedAddedActions['znuny.standalone_ticket.created']['uk'], $actionCol->formatState($logNew->action));

            $entityCol = $columns['entity_type'];
            $this->assertEquals($expectedEntityLabel, $entityCol->formatState($log->entity_type));
            $this->assertEquals($expectedAddedEntities['znuny_standalone_ticket']['uk'], $entityCol->formatState($logNew->entity_type));

            $dateCol = $columns['created_at'];
            $this->assertStringContainsString('17 липня 2026', $dateCol->formatState($log->created_at));

            // 3. Test infolist delegates correctly
            $infolistComponent = Livewire::actingAs($admin)->test(ViewAuditLog::class, ['record' => $logNew->id]);
            $schema = $infolistComponent->instance()->getSchema('infolist');
            $entries = [];

            $search = function ($components) use (&$search, &$entries) {
                foreach ($components as $c) {
                    if ($c instanceof Entry && method_exists($c, 'getName') && $c->getName()) {
                        $entries[$c->getName()] = $c;
                    }
                    if (method_exists($c, 'getChildComponents')) {
                        $search($c->getChildComponents());
                    }
                }
            };
            $search($schema->getComponents());

            $this->assertEquals($expectedAddedActions['znuny.standalone_ticket.created']['uk'], $entries['action']->formatState($logNew->action));
            $this->assertEquals($expectedAddedEntities['znuny_standalone_ticket']['uk'], $entries['entity_type']->formatState($logNew->entity_type));

        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_context_view_is_localized_and_structurally_sound()
    {
        $originalLocale = app()->getLocale();
        try {
            app()->setLocale('en');
            $this->assertEquals('No context', __('audit_logs.labels.no_context'));
            $this->assertEquals('Raw context', __('audit_logs.labels.raw_context'));
            $this->assertEquals('Stats', __('audit_logs.labels.stats'));
            $this->assertEquals('Warnings:', __('audit_logs.labels.warnings'));

            app()->setLocale('uk');
            $this->assertEquals('Контекст відсутній', __('audit_logs.labels.no_context'));
            $this->assertEquals('Необроблений контекст', __('audit_logs.labels.raw_context'));
            $this->assertEquals('Статистика', __('audit_logs.labels.stats'));
            $this->assertEquals('Попередження:', __('audit_logs.labels.warnings'));

            $bladePath = resource_path('views/filament/infolists/audit-log-context.blade.php');
            $content = file_get_contents($bladePath);

            $this->assertStringNotContainsString('>No context<', $content);
            $this->assertStringNotContainsString('>Raw context<', $content);
            $this->assertStringNotContainsString('Stats', $content);
            $this->assertStringNotContainsString('Warnings:', $content);

            $this->assertStringContainsString('{{ __(\'audit_logs.labels.no_context\') }}', $content);
            $this->assertStringContainsString('{{ __(\'audit_logs.labels.raw_context\') }}', $content);
            $this->assertStringContainsString('{{ __(\'audit_logs.labels.stats\') }}', $content);
            $this->assertStringContainsString('{{ __(\'audit_logs.labels.warnings\') }}', $content);

            $this->assertStringContainsString('@if($isEmpty)', $content);
            $this->assertStringContainsString('@foreach($context[\'changes\'] as $change)', $content);
            $this->assertStringContainsString('{{ json_encode($context, JSON_PRETTY_PRINT', $content);
            $this->assertStringContainsString('{{ $formatValue($value, $key) }}', $content);
        } finally {
            app()->setLocale($originalLocale);
        }
    }
}
