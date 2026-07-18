<?php

namespace Tests\Feature\Filament\Resources;

use App\Filament\Resources\ScheduledZnunyTaskRuns\Pages\ManageScheduledZnunyTaskRuns;
use App\Filament\Resources\ScheduledZnunyTaskRuns\ScheduledZnunyTaskRunResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\User;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ScheduledZnunyTaskRunResourceLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_labels_are_localized()
    {
        $originalLocale = app()->getLocale();
        try {
            app()->setLocale('en');
            $this->assertEquals('Run log entry', ScheduledZnunyTaskRunResource::getModelLabel());
            $this->assertEquals('Run log entries', ScheduledZnunyTaskRunResource::getPluralModelLabel());
            $this->assertEquals('Run log', ScheduledZnunyTaskRunResource::getNavigationLabel());
            $this->assertEquals(UserResource::getNavigationGroup(), ScheduledZnunyTaskRunResource::getNavigationGroup());

            app()->setLocale('uk');
            $this->assertEquals('Запис журналу запусків', ScheduledZnunyTaskRunResource::getModelLabel());
            $this->assertEquals('Записи журналу запусків', ScheduledZnunyTaskRunResource::getPluralModelLabel());
            $this->assertEquals('Журнал запусків', ScheduledZnunyTaskRunResource::getNavigationLabel());
            $this->assertEquals(UserResource::getNavigationGroup(), ScheduledZnunyTaskRunResource::getNavigationGroup());
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_page_titles_are_localized()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $originalLocale = app()->getLocale();
        try {
            // EN
            app()->setLocale('en');
            $list = Livewire::actingAs($admin)->test(ManageScheduledZnunyTaskRuns::class);
            $this->assertEquals('Run log entries', $list->instance()->getTitle());

            // UK
            app()->setLocale('uk');
            $listUk = Livewire::actingAs($admin)->test(ManageScheduledZnunyTaskRuns::class);
            $this->assertEquals('Записи журналу запусків', $listUk->instance()->getTitle());
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
            $component = Livewire::actingAs($admin)->test(ManageScheduledZnunyTaskRuns::class);

            $table = $component->instance()->getTable('table');
            $columns = $table->getColumns();

            $this->assertEquals('Записів журналу запусків не знайдено', $table->getEmptyStateHeading());

            $this->assertArrayHasKey('created_at', $columns);
            $this->assertEquals('Час', $columns['created_at']->getLabel());

            $this->assertArrayHasKey('task_name_snapshot', $columns);
            $this->assertEquals('Завдання', $columns['task_name_snapshot']->getLabel());

            $this->assertArrayHasKey('run_type', $columns);
            $this->assertEquals('Тип запуску', $columns['run_type']->getLabel());

            $this->assertArrayHasKey('scheduled_for', $columns);
            $this->assertEquals('Заплановано на', $columns['scheduled_for']->getLabel());

            $this->assertArrayHasKey('started_at', $columns);
            $this->assertEquals('Розпочато', $columns['started_at']->getLabel());

            $this->assertArrayHasKey('finished_at', $columns);
            $this->assertEquals('Завершено', $columns['finished_at']->getLabel());

            $this->assertArrayHasKey('duration_ms', $columns);
            $this->assertEquals('Час виконання', $columns['duration_ms']->getLabel());

            $this->assertArrayHasKey('status', $columns);
            $this->assertEquals('Статус', $columns['status']->getLabel());

            $this->assertArrayHasKey('ticket_number', $columns);
            $this->assertEquals('Номер заявки', $columns['ticket_number']->getLabel());

            $this->assertArrayHasKey('error_summary', $columns);
            $this->assertEquals('Опис помилки', $columns['error_summary']->getLabel());
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_filters_schema_is_localized()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $originalLocale = app()->getLocale();
        try {
            app()->setLocale('uk');
            $component = Livewire::actingAs($admin)->test(ManageScheduledZnunyTaskRuns::class);

            $table = $component->instance()->getTable('table');
            $filters = $table->getFilters();

            $this->assertArrayHasKey('scheduled_znuny_task_id', $filters);
            $this->assertEquals('Завдання', $filters['scheduled_znuny_task_id']->getLabel());

            $this->assertArrayHasKey('status', $filters);
            $this->assertEquals('Статус', $filters['status']->getLabel());

            $this->assertArrayHasKey('run_type', $filters);
            $this->assertEquals('Тип запуску', $filters['run_type']->getLabel());

            $this->assertArrayHasKey('created_at', $filters);
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_raw_values_are_preserved_in_database_and_translate_correctly()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $log = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => null,
            'task_name_snapshot' => 'Test Task',
            'run_type' => 'manual',
            'status' => 'success',
            'scheduled_for' => now(),
            'created_at' => Carbon::create(2026, 7, 17, 14, 10, 45, 'UTC'),
            'duration_ms' => 1500,
        ]);

        $this->assertEquals('manual', $log->run_type);
        $this->assertEquals('success', $log->status);

        $originalLocale = app()->getLocale();
        try {
            app()->setLocale('uk');

            // 1. Test shared presentation methods directly
            $expectedRunTypeLabel = __('scheduled_znuny_task_runs.run_types.manual');
            $this->assertNotEquals('manual', $expectedRunTypeLabel);
            $this->assertEquals($expectedRunTypeLabel, ScheduledZnunyTaskRunResource::runTypeLabel('manual'));

            $expectedStatusLabel = __('scheduled_znuny_task_runs.statuses.success');
            $this->assertNotEquals('success', $expectedStatusLabel);
            $this->assertEquals($expectedStatusLabel, ScheduledZnunyTaskRunResource::statusLabel('success'));

            $this->assertNull(ScheduledZnunyTaskRunResource::runTypeLabel(null));
            $this->assertEquals('', ScheduledZnunyTaskRunResource::runTypeLabel(''));
            $this->assertNull(ScheduledZnunyTaskRunResource::statusLabel(null));
            $this->assertEquals('', ScheduledZnunyTaskRunResource::statusLabel(''));

            // Unknown fallback
            $this->assertEquals('unknown_run_type', ScheduledZnunyTaskRunResource::runTypeLabel('unknown_run_type'));
            $this->assertEquals('unknown_status', ScheduledZnunyTaskRunResource::statusLabel('unknown_status'));

            // 2. Test table delegates correctly
            $component = Livewire::actingAs($admin)->test(ManageScheduledZnunyTaskRuns::class);
            $table = $component->instance()->getTable('table');
            $columns = $table->getColumns();

            $runTypeCol = $columns['run_type'];
            $this->assertEquals($expectedRunTypeLabel, $runTypeCol->formatState($log->run_type));

            $statusCol = $columns['status'];
            $this->assertEquals($expectedStatusLabel, $statusCol->formatState($log->status));

            $durationCol = $columns['duration_ms'];
            $this->assertEquals('1.5 сек', $durationCol->formatState($log->duration_ms));

            $dateCol = $columns['created_at'];
            $this->assertStringContainsString('17 липня 2026', $dateCol->formatState($log->created_at));
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_infolist_schema_is_localized()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $log = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => null,
            'task_name_snapshot' => 'Test Task',
            'run_type' => 'manual',
            'status' => 'success',
            'scheduled_for' => now(),
        ]);

        $originalLocale = app()->getLocale();
        try {
            app()->setLocale('uk');
            $component = Livewire::actingAs($admin)->test(ManageScheduledZnunyTaskRuns::class);

            $infolist = ScheduledZnunyTaskRunResource::infolist(Schema::make($component->instance()));
            $components = $infolist->getComponents();

            $entries = [];
            $search = function ($comps) use (&$search, &$entries) {
                foreach ($comps as $c) {
                    if ($c instanceof Entry && method_exists($c, 'getName') && $c->getName()) {
                        $entries[$c->getName()] = $c;
                    }

                    if (method_exists($c, 'getChildComponents')) {
                        $search($c->getChildComponents());
                    }
                }
            };
            $search($components);

            $this->assertArrayHasKey('task_name_snapshot', $entries);
            $this->assertEquals('Завдання', $entries['task_name_snapshot']->getLabel());

            $this->assertArrayHasKey('run_type', $entries);
            $this->assertEquals('Тип запуску', $entries['run_type']->getLabel());

            $this->assertArrayHasKey('status', $entries);
            $this->assertEquals('Статус', $entries['status']->getLabel());

            $this->assertArrayHasKey('scheduled_for', $entries);
            $this->assertEquals('Заплановано на', $entries['scheduled_for']->getLabel());

            $this->assertArrayHasKey('duration_ms', $entries);
            $this->assertEquals('Час виконання', $entries['duration_ms']->getLabel());
        } finally {
            app()->setLocale($originalLocale);
        }
    }
}
