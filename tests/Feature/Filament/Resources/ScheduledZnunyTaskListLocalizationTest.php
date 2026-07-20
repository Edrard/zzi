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
                ->assertSeeHtml('Наступний запуск')
                ->assertSeeHtml('Черга')
                ->assertSeeHtml('Користувач клієнта')
                ->assertSeeHtml('Власник')
                ->assertSeeHtml('Останній результат')
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
        $this->assertInstanceOf(SelectColumn::class, $component->instance()->getTable()->getColumn('queue_name'));
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
}
