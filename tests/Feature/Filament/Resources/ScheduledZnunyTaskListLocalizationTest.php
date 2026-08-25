<?php

namespace Tests\Feature\Filament\Resources;

use App\Filament\Resources\ScheduledZnunyTasks\Pages\ListScheduledZnunyTasks;
use App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource;
use App\Filament\Resources\ScheduledZnunyTasks\Widgets\SchedulerStatusConsole;
use App\Models\Setting;
use App\Models\User;
use App\Services\SchedulerSafetyService;
use App\Services\SettingsService;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Tests\TestCase;

class ScheduledZnunyTaskListLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create([
            'role' => 'admin',
            'show_scheduled_tasks_status_panel' => true,
        ]));
    }

    public function test_translation_file_key_parity(): void
    {
        $en = Lang::get('scheduled_znuny_tasks', [], 'en');
        $uk = Lang::get('scheduled_znuny_tasks', [], 'uk');

        $this->assertEqualsCanonicalizing(
            array_keys(Arr::dot($en)),
            array_keys(Arr::dot($uk)),
            'EN and UK translation files must have exact key parity.'
        );
    }

    public function test_it_preserves_navigation_and_permissions(): void
    {
        $this->assertTrue(ScheduledZnunyTaskResource::canAccess());

        $originalLocale = App::getLocale();

        try {
            App::setLocale('uk');
            $this->assertEquals('Заплановані завдання Znuny', ScheduledZnunyTaskResource::getNavigationLabel());
            $this->assertEquals('Заплановане завдання Znuny', ScheduledZnunyTaskResource::getModelLabel());
            $this->assertEquals('Заплановані завдання Znuny', ScheduledZnunyTaskResource::getPluralModelLabel());

            App::setLocale('en');
            $this->assertEquals('Scheduled Znuny tasks', ScheduledZnunyTaskResource::getNavigationLabel());
            $this->assertEquals('Scheduled Znuny task', ScheduledZnunyTaskResource::getModelLabel());
            $this->assertEquals('Scheduled Znuny tasks', ScheduledZnunyTaskResource::getPluralModelLabel());
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_scheduler_console_localization_uk(): void
    {
        $originalLocale = App::getLocale();
        App::setLocale('uk');

        try {
            Setting::updateOrCreate(['key' => 'scheduled_tasks_enabled'], ['value' => 'false']);
            Setting::updateOrCreate(['key' => 'scheduled_tasks_disabled_reason'], ['value' => 'Test reason']);
            SettingsService::clearAllCaches();

            Livewire::test(SchedulerStatusConsole::class)
                ->assertSeeHtml('Планувальник')
                ->assertSeeHtml('Вимкнено')
                ->assertSeeHtml('Вимкнено: Test reason')
                ->assertSeeHtml('Ніколи')
                ->assertSeeHtml('Немає')
                ->assertSeeHtml('Увімкнути')
                ->assertSeeHtml('Журнал запусків')
                ->assertSeeHtml('Налаштування пошти');

            Setting::updateOrCreate(['key' => 'scheduled_tasks_enabled'], ['value' => 'true']);
            Setting::updateOrCreate(['key' => 'scheduled_tasks_paused_until'], ['value' => now()->addHour()->toDateTimeString()]);
            SettingsService::clearAllCaches();

            Livewire::test(SchedulerStatusConsole::class)
                ->assertSeeHtml('Призупинено')
                ->assertSeeHtml('Призупинено до:')
                ->assertSeeHtml('Продовжити');

            Setting::updateOrCreate(['key' => 'scheduled_tasks_paused_until'], ['value' => '']);
            SettingsService::clearAllCaches();

            Livewire::test(SchedulerStatusConsole::class)
                ->assertSeeHtml('Увімкнено')
                ->assertSeeHtml('Призупинити')
                ->assertSeeHtml('Вимкнути');
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_scheduler_console_localization_en(): void
    {
        $originalLocale = App::getLocale();
        App::setLocale('en');

        try {
            Setting::updateOrCreate(['key' => 'scheduled_tasks_enabled'], ['value' => 'false']);
            Setting::updateOrCreate(['key' => 'scheduled_tasks_disabled_reason'], ['value' => 'Test reason']);
            SettingsService::clearAllCaches();

            Livewire::test(SchedulerStatusConsole::class)
                ->assertSeeHtml('Scheduler')
                ->assertSeeHtml('Disabled')
                ->assertSeeHtml('Disabled: Test reason')
                ->assertSeeHtml('Never')
                ->assertSeeHtml('None')
                ->assertSeeHtml('Enable')
                ->assertSeeHtml('Scheduler log')
                ->assertSeeHtml('Mail settings');

            Setting::updateOrCreate(['key' => 'scheduled_tasks_enabled'], ['value' => 'true']);
            Setting::updateOrCreate(['key' => 'scheduled_tasks_paused_until'], ['value' => now()->addHour()->toDateTimeString()]);
            SettingsService::clearAllCaches();

            Livewire::test(SchedulerStatusConsole::class)
                ->assertSeeHtml('Paused')
                ->assertSeeHtml('Paused until:')
                ->assertSeeHtml('Resume');

            Setting::updateOrCreate(['key' => 'scheduled_tasks_paused_until'], ['value' => '']);
            SettingsService::clearAllCaches();

            Livewire::test(SchedulerStatusConsole::class)
                ->assertSeeHtml('Enabled')
                ->assertSeeHtml('Pause')
                ->assertSeeHtml('Disable');
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_table_and_filters_localization_uk(): void
    {
        $originalLocale = App::getLocale();
        App::setLocale('uk');

        try {
            Livewire::test(ListScheduledZnunyTasks::class)
                ->assertSeeHtml('Пошук завдань...')
                ->assertSeeHtml('Усі черги')
                ->assertSeeHtml('Усі власники')
                ->assertSeeHtml('Усі стани')
                ->assertSeeHtml('Активні')
                ->assertSeeHtml('Неактивні')
                ->assertSeeHtml('Активне')
                ->assertSeeHtml('Назва')
                ->assertSeeHtml('Cron')
                ->assertSeeHtml('Наступний')
                ->assertSeeHtml('Черга')
                ->assertSeeHtml('Користувач')
                ->assertSeeHtml('Власник')
                ->assertSeeHtml('Останній')
                ->assertSeeHtml('Не розраховано') // placeholder check
                ->assertSeeHtml('Не вибрано')
                ->assertSeeHtml('Не визначено');
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_table_and_filters_localization_en(): void
    {
        $originalLocale = App::getLocale();
        App::setLocale('en');

        try {
            Livewire::test(ListScheduledZnunyTasks::class)
                ->assertSeeHtml('Search tasks...')
                ->assertSeeHtml('All queues')
                ->assertSeeHtml('All owners')
                ->assertSeeHtml('All statuses')
                ->assertSeeHtml('Active')
                ->assertSeeHtml('Inactive')
                ->assertSeeHtml('Active') // Table header
                ->assertSeeHtml('Name')
                ->assertSeeHtml('Cron')
                ->assertSeeHtml('Next run at')
                ->assertSeeHtml('Queue')
                ->assertSeeHtml('Customer user')
                ->assertSeeHtml('Owner')
                ->assertSeeHtml('Last result')
                ->assertSeeHtml('Not calculated')
                ->assertSeeHtml('Not selected')
                ->assertSeeHtml('Not resolved');
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_raw_values_and_callbacks_remain_unchanged(): void
    {
        // 9. Unknown, null, and empty status fallback.
        $this->assertEquals('unknown_status', ScheduledZnunyTaskResource::getStatusLabel('unknown_status'));
        $this->assertNull(ScheduledZnunyTaskResource::getStatusLabel(null));
        $this->assertSame('', ScheduledZnunyTaskResource::getStatusLabel(''));

        // 8. All status mappings, including duplicate.
        $originalLocale = App::getLocale();
        App::setLocale('uk');
        try {
            $this->assertEquals('Очікує', ScheduledZnunyTaskResource::getStatusLabel('pending'));
            $this->assertEquals('Виконується', ScheduledZnunyTaskResource::getStatusLabel('running'));
            $this->assertEquals('Успішно', ScheduledZnunyTaskResource::getStatusLabel('success'));
            $this->assertEquals('Пропущено', ScheduledZnunyTaskResource::getStatusLabel('skipped'));
            $this->assertEquals('Дублікат', ScheduledZnunyTaskResource::getStatusLabel('duplicate'));
            $this->assertEquals('Помилка', ScheduledZnunyTaskResource::getStatusLabel('failed'));
            $this->assertEquals('Невизначено', ScheduledZnunyTaskResource::getStatusLabel('uncertain'));
        } finally {
            App::setLocale($originalLocale);
        }

        // 12. Raw filter values remain unchanged.
        $component = Livewire::test(ListScheduledZnunyTasks::class);
        $this->assertTrue(property_exists($component->instance(), 'queueFilter'));
        $this->assertTrue(property_exists($component->instance(), 'ownerFilter'));
        $this->assertTrue(property_exists($component->instance(), 'activeFilter'));

        // 18. Inline edit callbacks remain present/connected.
        $this->assertInstanceOf(ToggleColumn::class, $component->instance()->getTable()->getColumn('enabled'));
        $this->assertInstanceOf(TextInputColumn::class, $component->instance()->getTable()->getColumn('cron_expression'));
        $this->assertInstanceOf(\Filament\Tables\Columns\TextColumn::class, $component->instance()->getTable()->getColumn('queue_name'));
    }

    public function test_scheduler_actions_mocking(): void
    {
        $mock = $this->mock(SchedulerSafetyService::class);
        $mock->shouldReceive('enableScheduler')->once();

        $originalLocale = App::getLocale();
        App::setLocale('en');

        try {
            Setting::updateOrCreate(['key' => 'scheduled_tasks_enabled'], ['value' => 'false']);
            SettingsService::clearAllCaches();

            Livewire::test(SchedulerStatusConsole::class)
                ->call('enableScheduler')
                ->assertNotified(Notification::make()->title('Scheduler enabled')->success());

            $mock->shouldReceive('disableScheduler')->with('Manually disabled by admin')->once();

            Setting::updateOrCreate(['key' => 'scheduled_tasks_enabled'], ['value' => 'true']);
            SettingsService::clearAllCaches();

            Livewire::test(SchedulerStatusConsole::class)
                ->call('disableScheduler')
                ->assertNotified(Notification::make()->title('Scheduler disabled')->warning());

            $mock->shouldReceive('pauseScheduler')->with('Manually paused by admin')->once();

            Livewire::test(SchedulerStatusConsole::class)
                ->call('pauseScheduler')
                ->assertNotified(Notification::make()->title('Scheduler paused')->warning());

            $mock->shouldReceive('clearPause')->once();

            Setting::updateOrCreate(['key' => 'scheduled_tasks_paused_until'], ['value' => now()->addHour()->toDateTimeString()]);
            SettingsService::clearAllCaches();

            Livewire::test(SchedulerStatusConsole::class)
                ->call('clearPause')
                ->assertNotified(Notification::make()->title('Pause cleared')->success());

        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_edit_page_and_table_notifications_localization(): void
    {
        $originalLocale = App::getLocale();
        try {
            App::setLocale('en');
            $this->assertEquals('Queue run', __('scheduled_znuny_tasks.actions.queue_run'));
            $this->assertEquals('Runs', __('scheduled_znuny_tasks.actions.runs'));
            $this->assertEquals('Run queued', __('scheduled_znuny_tasks.notifications.run_queued.title'));
            $this->assertEquals('The run has been queued, but the scheduler is currently disabled or paused. It will remain pending.', __('scheduled_znuny_tasks.notifications.run_queued.body_paused'));
            $this->assertEquals('The run has been queued and will be processed by the scheduler shortly.', __('scheduled_znuny_tasks.notifications.run_queued.body_active'));

            $this->assertEquals('Cannot enable task', __('scheduled_znuny_tasks.notifications.cannot_enable_task.title'));
            $this->assertEquals('Task is incomplete:', __('scheduled_znuny_tasks.notifications.cannot_enable_task.body_incomplete'));
            $this->assertEquals('Could not calculate next run time. Check timezone and cron expression.', __('scheduled_znuny_tasks.notifications.cannot_enable_task.body_invalid_cron'));

            $this->assertEquals('Validation error', __('scheduled_znuny_tasks.notifications.validation_error.title'));
            $this->assertEquals('Invalid 5-field cron expression.', __('scheduled_znuny_tasks.notifications.validation_error.invalid_cron'));

            $this->assertEquals('Cannot clear Queue', __('scheduled_znuny_tasks.notifications.cannot_clear_queue.title'));
            $this->assertEquals('Active tasks require a Queue.', __('scheduled_znuny_tasks.notifications.cannot_clear_queue.body'));
            $this->assertEquals('Cannot clear Customer User', __('scheduled_znuny_tasks.notifications.cannot_clear_customer_user.title'));
            $this->assertEquals('Active tasks require a Customer User.', __('scheduled_znuny_tasks.notifications.cannot_clear_customer_user.body'));
            $this->assertEquals('Cannot clear Owner', __('scheduled_znuny_tasks.notifications.cannot_clear_owner.title'));
            $this->assertEquals('Active tasks require an Owner.', __('scheduled_znuny_tasks.notifications.cannot_clear_owner.body'));

            App::setLocale('uk');
            $this->assertEquals('Поставити виконання в чергу', __('scheduled_znuny_tasks.actions.queue_run'));
            $this->assertEquals('Виконання', __('scheduled_znuny_tasks.actions.runs'));
            $this->assertEquals('Виконання поставлено в чергу', __('scheduled_znuny_tasks.notifications.run_queued.title'));
            $this->assertEquals('Виконання поставлено в чергу, але планувальник наразі вимкнений або призупинений. Воно залишатиметься в очікуванні.', __('scheduled_znuny_tasks.notifications.run_queued.body_paused'));
            $this->assertEquals('Виконання поставлено в чергу та незабаром буде оброблено планувальником.', __('scheduled_znuny_tasks.notifications.run_queued.body_active'));

            $this->assertEquals('Неможливо увімкнути завдання', __('scheduled_znuny_tasks.notifications.cannot_enable_task.title'));
            $this->assertEquals('Завдання неповне:', __('scheduled_znuny_tasks.notifications.cannot_enable_task.body_incomplete'));
            $this->assertEquals('Не вдалося обчислити час наступного виконання. Перевірте часовий пояс і cron-вираз.', __('scheduled_znuny_tasks.notifications.cannot_enable_task.body_invalid_cron'));

            $this->assertEquals('Помилка перевірки', __('scheduled_znuny_tasks.notifications.validation_error.title'));
            $this->assertEquals('Недійсний cron-вираз із п’яти полів.', __('scheduled_znuny_tasks.notifications.validation_error.invalid_cron'));

            $this->assertEquals('Неможливо очистити чергу', __('scheduled_znuny_tasks.notifications.cannot_clear_queue.title'));
            $this->assertEquals('Для активних завдань потрібно вказати чергу.', __('scheduled_znuny_tasks.notifications.cannot_clear_queue.body'));
            $this->assertEquals('Неможливо очистити клієнта-користувача', __('scheduled_znuny_tasks.notifications.cannot_clear_customer_user.title'));
            $this->assertEquals('Для активних завдань потрібно вказати клієнта-користувача.', __('scheduled_znuny_tasks.notifications.cannot_clear_customer_user.body'));
            $this->assertEquals('Неможливо очистити власника', __('scheduled_znuny_tasks.notifications.cannot_clear_owner.title'));
            $this->assertEquals('Для активних завдань потрібно вказати власника.', __('scheduled_znuny_tasks.notifications.cannot_clear_owner.body'));

            $editPath = app_path('Filament/Resources/ScheduledZnunyTasks/Pages/EditScheduledZnunyTask.php');
            $tablePath = app_path('Filament/Resources/ScheduledZnunyTasks/Tables/ScheduledZnunyTasksTable.php');

            $editContent = file_get_contents($editPath);
            $tableContent = file_get_contents($tablePath);

            $this->assertStringNotContainsString("->label('Queue run')", $editContent);
            $this->assertStringNotContainsString("->label('Runs')", $editContent);
            $this->assertStringNotContainsString("->title('Run Queued')", $editContent);
            $this->assertStringNotContainsString("->title('Cannot enable task')", $tableContent);
            $this->assertStringNotContainsString("->title('Validation Error')", $tableContent);
            $this->assertStringNotContainsString("->title('Cannot clear Queue')", $tableContent);

            $this->assertStringContainsString("Action::make('enqueue_run')", $editContent);
            $this->assertStringContainsString('Notification::make()->title(__(\'scheduled_znuny_tasks.notifications.run_queued.title\'))', $editContent);
            $this->assertStringContainsString('$safetyService->isSchedulerEnabled()', $editContent);

            $this->assertStringContainsString("ToggleColumn::make('enabled')", $tableContent);
            $this->assertStringContainsString('$record->missingSchedulingRequirements()', $tableContent);
            $this->assertStringContainsString('Notification::make()', $tableContent);
            $this->assertStringContainsString('\\n- ".implode("\\n- ", $missing)', $tableContent);

        } finally {
            App::setLocale($originalLocale);
        }
    }
}
