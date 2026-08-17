<?php

namespace Tests\Feature\Scheduler;

use App\Filament\Resources\ScheduledZnunyTasks\Pages\CreateScheduledZnunyTask;
use App\Filament\Resources\ScheduledZnunyTasks\Pages\EditScheduledZnunyTask;
use App\Filament\Resources\ScheduledZnunyTasks\Pages\ListScheduledZnunyTasks;
use App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource;
use App\Filament\Resources\ScheduledZnunyTasks\Widgets\SchedulerStatusConsole;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\Setting;
use App\Models\SystemAlert;
use App\Models\User;
use App\Services\Cron\CronService;
use App\Services\ScheduledZnunyTaskRunProcessor;
use App\Services\ScheduledZnunyTicketCreationService;
use App\Services\SchedulerSafetyService;
use App\Services\SettingsService;
use App\Services\Support\DateTimeDisplayService;
use App\Services\Znuny\ZnunyCachedLookupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class ScheduledZnunyTaskResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('db:seed', ['--class' => 'SettingsSeeder']);

        $lookupMock = \Mockery::mock(ZnunyCachedLookupService::class)->makePartial();
        $lookupMock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
        $lookupMock->shouldReceive('getTicketPriorities')->andReturn(['3 normal' => '3 normal', '5 very high' => '5 very high']);
        $lookupMock->shouldReceive('getTicketStates')->andReturn(['new' => 'new', 'open' => 'open']);
        $this->app->instance(ZnunyCachedLookupService::class, $lookupMock);
    }

    public function test_admin_can_access_scheduled_tasks()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get(ScheduledZnunyTaskResource::getUrl('index'))
            ->assertSuccessful();
    }

    public function test_operator_cannot_access_scheduled_tasks()
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $this->actingAs($operator)
            ->get(ScheduledZnunyTaskResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_viewer_cannot_access_scheduled_tasks()
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)
            ->get(ScheduledZnunyTaskResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_scheduled_task_can_be_created_disabled_as_draft()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin);

        Livewire::test(CreateScheduledZnunyTask::class)
            ->fillForm([
                'name' => 'Draft Task',
                'enabled' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('scheduled_znuny_tasks', [
            'name' => 'Draft Task',
            'enabled' => false,
        ]);
    }

    public function test_table_enable_is_blocked_when_cron_invalid()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $task = ScheduledZnunyTask::create([
            'name' => 'Invalid Cron Task',
            'enabled' => false,
            'cron_expression' => 'invalid cron',
            'queue_name' => 'Support',
            'owner_id' => 2,
            'owner_login' => 'john.doe',
            'subject' => 'Test',
            'body' => 'Test',
        ]);

        $this->actingAs($admin);

        Livewire::test(ListScheduledZnunyTasks::class)
            ->call('updateTableColumnState', 'enabled', $task->id, true)
            ->assertNotified('Cannot enable task');

        $this->assertDatabaseHas('scheduled_znuny_tasks', [
            'id' => $task->id,
            'enabled' => false,
        ]);
    }

    public function test_table_enable_is_blocked_when_required_fields_missing()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $task = ScheduledZnunyTask::create([
            'name' => 'Missing Queue Task',
            'enabled' => false,
            'cron_expression' => '* * * * *',
            'queue_name' => null, // Missing
        ]);

        $this->actingAs($admin);

        Livewire::test(ListScheduledZnunyTasks::class)
            ->call('updateTableColumnState', 'enabled', $task->id, true)
            ->assertNotified('Cannot enable task');

        $this->assertDatabaseHas('scheduled_znuny_tasks', [
            'id' => $task->id,
            'enabled' => false,
        ]);
    }

    public function test_table_disable_is_allowed()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $task = ScheduledZnunyTask::create([
            'name' => 'Valid Task',
            'enabled' => true,
            'cron_expression' => '* * * * *',
            'queue_name' => 'Support',
            'owner_id' => 2,
            'owner_login' => 'john.doe',
            'subject' => 'Test',
            'body' => 'Test',
        ]);

        $this->actingAs($admin);

        Livewire::test(ListScheduledZnunyTasks::class)
            ->call('updateTableColumnState', 'enabled', $task->id, false);

        $this->assertDatabaseHas('scheduled_znuny_tasks', [
            'id' => $task->id,
            'enabled' => false,
        ]);
    }

    public function test_soft_delete_keeps_run_log_rows()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $task = ScheduledZnunyTask::create([
            'name' => 'To Be Deleted',
            'enabled' => false,
        ]);

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'manual',
            'scheduled_for' => now(),
            'status' => 'success',
        ]);

        $this->actingAs($admin);

        Livewire::test(EditScheduledZnunyTask::class, [
            'record' => $task->getRouteKey(),
        ])
            ->callAction('delete')
            ->assertSuccessful();

        $this->assertSoftDeleted('scheduled_znuny_tasks', [
            'id' => $task->id,
        ]);

        $this->assertDatabaseHas('scheduled_znuny_task_runs', [
            'id' => $run->id,
            'task_name_snapshot' => 'To Be Deleted',
        ]);
    }

    public function test_enable_is_blocked_when_customer_user_login_is_missing()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $task = ScheduledZnunyTask::create([
            'name' => 'Missing Customer User Task',
            'enabled' => false,
            'cron_expression' => '* * * * *',
            'queue_name' => 'Support',
            'owner_id' => 2,
            'owner_login' => 'john.doe',
            'customer_user_login' => null, // Missing
            'subject' => 'Test',
            'body' => 'Test',
        ]);

        $this->actingAs($admin);

        Livewire::test(ListScheduledZnunyTasks::class)
            ->call('updateTableColumnState', 'enabled', $task->id, true)
            ->assertNotified('Cannot enable task');

        $this->assertDatabaseHas('scheduled_znuny_tasks', [
            'id' => $task->id,
            'enabled' => false,
        ]);
    }

    public function test_enable_is_blocked_when_next_run_at_cannot_be_calculated()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $task = ScheduledZnunyTask::create([
            'name' => 'Valid Task',
            'enabled' => false,
            'cron_expression' => '* * * * *',
            'timezone' => 'UTC',
            'queue_name' => 'Support',
            'owner_id' => 2,
            'owner_login' => 'john.doe',
            'customer_user_login' => 'client',
            'subject' => 'Test',
            'body' => 'Test',
        ]);

        $this->actingAs($admin);

        // Mock CronService to return null for next run
        $mock = \Mockery::mock(CronService::class)->makePartial();
        $mock->shouldReceive('isValid')->andReturn(true);
        $mock->shouldReceive('calculateNextRun')->andReturn(null);
        $this->app->instance(CronService::class, $mock);

        Livewire::test(ListScheduledZnunyTasks::class)
            ->call('updateTableColumnState', 'enabled', $task->id, true)
            ->assertNotified('Cannot enable task');

        $this->assertDatabaseHas('scheduled_znuny_tasks', [
            'id' => $task->id,
            'enabled' => false,
        ]);
    }

    public function test_invalid_inline_cron_does_not_overwrite_existing()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $task = ScheduledZnunyTask::create([
            'name' => 'Valid Task',
            'enabled' => false,
            'cron_expression' => '0 * * * *',
            'next_run_at' => now()->addHour(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListScheduledZnunyTasks::class)
            ->call('updateTableColumnState', 'cron_expression', $task->id, 'invalid')
            ->assertNotified(__('scheduled_znuny_tasks.notifications.validation_error.title'));

        $this->assertDatabaseHas('scheduled_znuny_tasks', [
            'id' => $task->id,
            'cron_expression' => '0 * * * *',
        ]);
    }

    public function test_empty_inline_owner_does_not_overwrite_existing()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $task = ScheduledZnunyTask::create([
            'name' => 'Valid Task',
            'enabled' => true,
            'owner_id' => 2,
            'owner_login' => 'original.owner',
        ]);

        $this->actingAs($admin);

        Livewire::test(ListScheduledZnunyTasks::class)
            ->call('updateTableColumnState', 'owner_id', $task->id, '')
            ->assertNotified('Cannot clear Owner');

        $this->assertDatabaseHas('scheduled_znuny_tasks', [
            'id' => $task->id,
            'owner_id' => 2,
        ]);
    }

    public function test_manual_enqueue_action_creates_pending_run()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        app(SchedulerSafetyService::class)->enableScheduler();

        $task = ScheduledZnunyTask::create([
            'name' => 'Valid Task',
            'enabled' => true,
            'cron_expression' => '* * * * *',
            'timezone' => 'UTC',
            'queue_name' => 'Support',
            'owner_id' => 2,
            'owner_login' => 'john.doe',
            'customer_user_login' => 'client',
            'subject' => 'Test',
            'body' => 'Test',
        ]);

        $mockProcessor = $this->createMock(ScheduledZnunyTaskRunProcessor::class);
        $mockProcessor->expects($this->never())->method('processNextBatch');
        $this->app->instance(ScheduledZnunyTaskRunProcessor::class, $mockProcessor);

        $mockService = $this->createMock(ScheduledZnunyTicketCreationService::class);
        $mockService->expects($this->never())->method('createTicketFromTask');
        $this->app->instance(ScheduledZnunyTicketCreationService::class, $mockService);

        Livewire::test(EditScheduledZnunyTask::class, ['record' => $task->getRouteKey()])
            ->assertActionExists('enqueue_run')
            ->callAction('enqueue_run')
            ->assertNotified(__('scheduled_znuny_tasks.notifications.run_queued.title'));

        $this->assertDatabaseHas('scheduled_znuny_task_runs', [
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'manual',
            'status' => 'pending',
        ]);
    }

    public function test_manual_enqueue_action_warns_when_scheduler_disabled()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        app(SchedulerSafetyService::class)->disableScheduler('Test Disable');

        $task = ScheduledZnunyTask::create([
            'name' => 'Valid Task',
            'enabled' => true,
            'cron_expression' => '* * * * *',
            'timezone' => 'UTC',
            'queue_name' => 'Support',
            'owner_id' => 2,
            'owner_login' => 'john.doe',
            'customer_user_login' => 'client',
            'subject' => 'Test',
            'body' => 'Test',
        ]);

        $mockProcessor = $this->createMock(ScheduledZnunyTaskRunProcessor::class);
        $mockProcessor->expects($this->never())->method('processNextBatch');
        $this->app->instance(ScheduledZnunyTaskRunProcessor::class, $mockProcessor);

        $mockService = $this->createMock(ScheduledZnunyTicketCreationService::class);
        $mockService->expects($this->never())->method('createTicketFromTask');
        $this->app->instance(ScheduledZnunyTicketCreationService::class, $mockService);

        Livewire::test(EditScheduledZnunyTask::class, ['record' => $task->getRouteKey()])
            ->callAction('enqueue_run')
            ->assertNotified(__('scheduled_znuny_tasks.notifications.run_queued.title'));

        $this->assertDatabaseHas('scheduled_znuny_task_runs', [
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'manual',
            'status' => 'pending',
        ]);
    }

    public function test_edit_page_queue_run_unavailable_for_incomplete_task()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $task = ScheduledZnunyTask::create([
            'name' => 'Incomplete Task',
            'enabled' => false, // draft
        ]);

        Livewire::test(EditScheduledZnunyTask::class, ['record' => $task->getRouteKey()])
            ->assertActionHidden('enqueue_run');
    }

    public function test_scheduled_panel_is_visible_by_default()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        Livewire::test(ListScheduledZnunyTasks::class)
            ->assertSeeLivewire(SchedulerStatusConsole::class);
    }

    public function test_scheduled_panel_is_hidden_when_user_disables_setting()
    {
        $admin = User::factory()->create(['role' => 'admin', 'show_scheduled_tasks_status_panel' => false]);
        $this->actingAs($admin);

        Livewire::test(ListScheduledZnunyTasks::class)
            ->assertDontSeeLivewire(SchedulerStatusConsole::class);
    }

    public function test_scheduled_tasks_table_has_filters()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        Livewire::test(ListScheduledZnunyTasks::class)
            ->assertSet('taskSearch', '')
            ->assertSet('queueFilter', '')
            ->assertSet('ownerFilter', '')
            ->assertSet('activeFilter', 'all');
    }

    public function test_scheduled_task_create_form_has_lock_and_defaults()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        // General settings timezone default is UTC since not configured in test
        $component = Livewire::test(CreateScheduledZnunyTask::class)
            ->assertFormFieldExists('timezone')
            ->assertFormFieldExists('priority_name')
            ->assertFormFieldExists('state_name')
            ->assertFormFieldExists('lock_name');

        // Check defaults are populated
        $this->assertEquals('UTC', $component->get('data.timezone'));
        $this->assertEquals('3 normal', $component->get('data.priority_name'));
        $this->assertEquals('new', $component->get('data.state_name'));
        $this->assertEquals('lock', $component->get('data.lock_name'));
    }

    public function test_scheduled_task_edit_form_keeps_saved_values()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $task = ScheduledZnunyTask::create([
            'name' => 'Valid Task',
            'enabled' => false,
            'cron_expression' => '* * * * *',
            'timezone' => 'Europe/Berlin',
            'priority_name' => '5 very high',
            'state_name' => 'open',
            'lock_name' => 'unlock',
        ]);

        $component = Livewire::test(EditScheduledZnunyTask::class, ['record' => $task->getRouteKey()]);

        $this->assertEquals('Europe/Berlin', $component->get('data.timezone'));
        $this->assertEquals('5 very high', $component->get('data.priority_name'));
        $this->assertEquals('open', $component->get('data.state_name'));
        $this->assertEquals('unlock', $component->get('data.lock_name'));
    }

    public function test_enabled_scheduled_task_requires_all_essential_fields()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        Livewire::test(CreateScheduledZnunyTask::class)
            ->fillForm([
                'name' => 'Invalid Enabled Task',
                'enabled' => true,
                'cron_expression' => null,
                'timezone' => null,
                'queue_name' => null,
                'owner_id' => null,
                'customer_user_login' => null,
                'subject' => null,
                'body' => null,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'cron_expression' => 'required',
                'timezone' => 'required',
                'queue_name' => 'required',
                'owner_id' => 'required',
                'customer_user_login' => 'required',
                'subject' => 'required',
                'body' => 'required',
            ]);
    }

    public function test_edit_form_hydrates_next_run_preview_immediately()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $task = ScheduledZnunyTask::create([
            'name' => 'Valid Task',
            'enabled' => false,
            'cron_expression' => '0 12 * * *', // Noon daily
            'timezone' => 'UTC',
        ]);

        $component = Livewire::test(EditScheduledZnunyTask::class, ['record' => $task->getRouteKey()]);

        $nextRun = $component->get('data.next_run_at');
        $this->assertNotNull($nextRun);
        $this->assertStringContainsString('12:00:00', $nextRun);
    }

    public function test_create_form_updates_next_run_preview_live()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        Carbon::setTestNow('2026-07-09 00:00:00');

        $component = Livewire::test(CreateScheduledZnunyTask::class);
        $this->assertNull($component->get('data.next_run_at'));

        $component->set('data.cron_expression', '0 15 * * *')
            ->set('data.timezone', 'Europe/Kyiv');

        $nextRun = $component->get('data.next_run_at');
        $this->assertNotNull($nextRun);

        // The display string should show local time for Europe/Kyiv
        $component->assertSee('15:00:00 Europe/Kyiv');

        // Changing timezone should recalculate and update display
        $component->set('data.timezone', 'Asia/Tokyo');
        $component->assertSee('15:00:00 Asia/Tokyo');

        // invalid cron
        $component->set('data.cron_expression', 'invalid');
        $this->assertNull($component->get('data.next_run_at'));
        $component->assertSee('N/A');

        Carbon::setTestNow();
    }

    public function test_resource_does_not_have_view_action()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $task = ScheduledZnunyTask::create([
            'name' => 'View Action Test Task',
            'enabled' => false,
        ]);

        Livewire::test(ListScheduledZnunyTasks::class)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('view');
    }

    public function test_record_url_goes_to_edit_page()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $task = ScheduledZnunyTask::create([
            'name' => 'Direct Edit Test Task',
            'enabled' => false,
        ]);

        $url = ScheduledZnunyTaskResource::getUrl('edit', ['record' => $task]);
        $this->assertStringEndsWith("/{$task->id}", $url);

        $response = $this->get($url);
        $response->assertSuccessful();
        $response->assertSee('Direct Edit Test Task');
    }

    public function test_next_run_at_is_calculated_dynamically_without_mutating_scheduler_cursor()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        // Provide safe expectations for the existing partial mock to prevent uninitialized reader errors during table render
        $lookupMock = $this->app->make(ZnunyCachedLookupService::class);
        $lookupMock->shouldReceive('getFilteredQueueOptions')->andReturn([])->byDefault();
        $lookupMock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->andReturn([])->byDefault();
        $lookupMock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->andReturn([])->byDefault();

        $originalTimezone = Setting::where('key', 'app_display_timezone')->value('value');

        try {
            Setting::updateOrCreate(['key' => 'app_display_timezone'], ['value' => 'Europe/Kyiv']);

            // Set now to a fixed time: 2026-07-09 10:00:00 UTC
            // Europe/Kyiv is UTC+3 in July, so local time is 13:00.
            Carbon::setTestNow('2026-07-09 10:00:00');

            $task1 = ScheduledZnunyTask::create([
                'name' => 'Disabled Valid Task (NULL)',
                'enabled' => false,
                'cron_expression' => '0 15 * * *', // 15:00 Kyiv daily
                'timezone' => 'Europe/Kyiv',
                'next_run_at' => null, // Should dynamically calculate
            ]);

            $task2 = ScheduledZnunyTask::create([
                'name' => 'Disabled Valid Task (Stale)',
                'enabled' => false,
                'cron_expression' => '0 15 * * *',
                'timezone' => 'Europe/Kyiv',
                'next_run_at' => '2026-07-09 08:00:00', // Past 08:00 UTC, must dynamically recalculate
            ]);

            $task3 = ScheduledZnunyTask::create([
                'name' => 'Enabled Valid Task (Stale)',
                'enabled' => true,
                'cron_expression' => '0 15 * * *',
                'timezone' => 'Europe/Kyiv',
                'next_run_at' => '2026-07-09 08:00:00', // Past 08:00 UTC, must dynamically recalculate
                'queue_name' => 'Support',
                'owner_id' => 2,
                'owner_login' => 'john.doe',
                'customer_user_login' => 'client',
                'subject' => 'Test',
                'body' => 'Test',
            ]);

            $task4 = ScheduledZnunyTask::create([
                'name' => 'Task Invalid',
                'enabled' => false,
                'cron_expression' => 'invalid',
                'timezone' => 'UTC',
                'next_run_at' => null,
            ]);

            // Dynamically compute the expected representation
            $cronService = app(CronService::class);
            $next = $cronService->calculateNextRun('0 15 * * *', 'Europe/Kyiv');
            $this->assertNotNull($next);

            $expectedState = $next->utc()->toDateTimeString();
            $this->assertSame('2026-07-09 12:00:00', $expectedState);

            $expectedDisplay = app(DateTimeDisplayService::class)->formatDateTime($expectedState);
            $this->assertSame('Europe/Kyiv', app(DateTimeDisplayService::class)->timezone());
            $this->assertSame('Jul 9, 2026 15:00:00', $expectedDisplay);

            $component = Livewire::test(ListScheduledZnunyTasks::class);

            $component->assertTableColumnStateSet('next_run_at', $expectedState, $task1);
            $component->assertTableColumnStateSet('next_run_at', $expectedState, $task2);
            $component->assertTableColumnStateSet('next_run_at', $expectedState, $task3);
            $component->assertTableColumnStateSet('next_run_at', null, $task4);

            $component->assertSee($expectedDisplay);

            // 4. Rendering the table does not mutate persisted next_run_at.
            $this->assertDatabaseHas('scheduled_znuny_tasks', [
                'id' => $task1->id,
                'next_run_at' => null,
            ]);
            $this->assertDatabaseHas('scheduled_znuny_tasks', [
                'id' => $task2->id,
                'next_run_at' => '2026-07-09 08:00:00',
            ]);
            $this->assertDatabaseHas('scheduled_znuny_tasks', [
                'id' => $task3->id,
                'next_run_at' => '2026-07-09 08:00:00', // Ensure enabled task cursor isn't updated
            ]);
        } finally {
            if ($originalTimezone === null) {
                Setting::where('key', 'app_display_timezone')->delete();
            } else {
                Setting::updateOrCreate(['key' => 'app_display_timezone'], ['value' => $originalTimezone]);
            }
            Carbon::setTestNow();
        }
    }

    public function test_headers_use_client_side_sorting_contract()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $component = Livewire::test(ListScheduledZnunyTasks::class);
        $table = $component->instance()->getTable();

        foreach ([
            'enabled',
            'name',
            'cron_expression',
            'next_run_at',
            'queue_name',
            'customer_user_login',
            'owner_id',
            'last_status',
        ] as $columnName) {
            $column = $table->getColumn($columnName);

            $this->assertNotNull($column, "Expected table column [{$columnName}] to exist.");
            $this->assertFalse(
                $column->isSortable(),
                "Expected table column [{$columnName}] to leave server-side sorting disabled."
            );
        }
    }

    public function test_enable_is_blocked_when_owner_id_is_missing_but_login_is_present()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $task = ScheduledZnunyTask::create([
            'name' => 'Legacy Owner Task',
            'enabled' => false,
            'cron_expression' => '* * * * *',
            'queue_name' => 'Support',
            'owner_login' => '2', // Has legacy value
            'owner_id' => null,   // But no ID
            'customer_user_login' => 'client',
            'subject' => 'Test',
            'body' => 'Test',
        ]);

        $this->actingAs($admin);

        Livewire::test(ListScheduledZnunyTasks::class)
            ->call('updateTableColumnState', 'enabled', $task->id, true)
            ->assertNotified('Cannot enable task');

        $this->assertDatabaseHas('scheduled_znuny_tasks', [
            'id' => $task->id,
            'enabled' => false,
        ]);
    }

    public function test_form_owner_selection_saves_owner_id()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $mock = app(ZnunyCachedLookupService::class);
        $mock->shouldReceive('getFilteredQueueOptions')->andReturn(['Support' => 'Support']);
        $mock->shouldReceive('resolveTemplateCandidate')->andReturn(null);
        $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->andReturn([5 => 'Five']);

        Livewire::test(CreateScheduledZnunyTask::class)
            ->fillForm([
                'name' => 'Form Owner Task',
                'enabled' => false,
                'queue_name' => 'Support',
                'priority_name' => '3 normal',
                'state_name' => 'new',
            ])
            ->set('data.owner_id', 5) // triggers updated->afterStateUpdated
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('scheduled_znuny_tasks', [
            'name' => 'Form Owner Task',
            'owner_id' => 5,
            'owner_login' => 'Five',
        ]);
    }

    public function test_inline_owner_update_saves_owner_id()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $task = ScheduledZnunyTask::create([
            'name' => 'Valid Task',
            'enabled' => false,
            'queue_name' => 'ValidQueue',
            'owner_id' => 2,
        ]);

        $mock = app(ZnunyCachedLookupService::class);
        $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->andReturn([9 => 'Nine']);

        Livewire::test(ListScheduledZnunyTasks::class)
            ->call('updateTableColumnState', 'owner_id', $task->id, 9)
            ->assertSuccessful();

        $this->assertDatabaseHas('scheduled_znuny_tasks', [
            'id' => $task->id,
            'owner_id' => 9,
            'owner_login' => 'Nine',
        ]);
    }

    public function test_queue_selection_auto_fills_owner_id_and_login()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $mock = app(ZnunyCachedLookupService::class);
        $mock->shouldReceive('getFilteredQueueOptions')->andReturn(['NewQueue' => 'NewQueue']);
        $mock->shouldReceive('resolveTemplateCandidate')->with('NewQueue')->andReturn(null);
        // Only one owner option
        $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->with('NewQueue')->andReturn([7 => 'Seven']);

        Livewire::test(CreateScheduledZnunyTask::class)
            ->fillForm([
                'name' => 'Valid Task',
                'enabled' => false,
            ])
            ->set('data.queue_name', 'NewQueue')
            ->assertSet('data.owner_id', 7)
            ->assertSet('data.owner_login', 'Seven');
    }

    public function test_list_filters_do_not_throw_reset_page_exception()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $component = Livewire::test(ListScheduledZnunyTasks::class);

        // Updating these properties should not crash with resetTablePage missing
        $component->set('taskSearch', 'Test')
            ->assertSuccessful();

        $component->set('queueFilter', 'Support')
            ->assertSuccessful();

        $component->set('ownerFilter', '2')
            ->assertSuccessful();

        $component->set('activeFilter', 'active')
            ->assertSuccessful();
    }

    public function test_owner_options_resolution_order()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        // Task with known owner_login
        ScheduledZnunyTask::create([
            'name' => 'Task A',
            'enabled' => false,
            'owner_id' => 10,
            'owner_login' => 'Alice',
            'queue_name' => 'Queue A',
        ]);

        // Task with missing owner_login, to be resolved via API mock
        ScheduledZnunyTask::create([
            'name' => 'Task B',
            'enabled' => false,
            'owner_id' => 11,
            'owner_login' => null,
            'queue_name' => 'Queue B',
        ]);

        // Task with missing owner_login, API mock fails/does not find it, fallback to raw ID
        ScheduledZnunyTask::create([
            'name' => 'Task C',
            'enabled' => false,
            'owner_id' => 12,
            'owner_login' => null,
            'queue_name' => 'Queue C',
        ]);

        // Task with owner_login perfectly matching owner_id (simulates old bug)
        ScheduledZnunyTask::create([
            'name' => 'Task Buggy',
            'enabled' => false,
            'owner_id' => 13,
            'owner_login' => '13',
            'queue_name' => 'Queue D',
        ]);

        $mock = app(ZnunyCachedLookupService::class);
        $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')
            ->with(null)
            ->andReturn([
                '11' => 'Bob Human',
                13 => 'Charlie Human',
            ]);

        $component = Livewire::test(ListScheduledZnunyTasks::class);

        $options = $component->instance()->getOwnerOptions();

        $this->assertArrayHasKey(10, $options);
        $this->assertEquals('Alice', $options[10]); // Fallback legacy

        $this->assertArrayHasKey(11, $options);
        $this->assertEquals('Bob Human', $options[11]); // Canonical

        $this->assertArrayHasKey(12, $options);
        $this->assertEquals('Owner ID: 12', $options[12]); // Fallback ID

        $this->assertArrayHasKey(13, $options);
        $this->assertEquals('Charlie Human', $options[13]); // Canonical ignores legacy '13'

        // Ensure selecting an owner filter applies successfully and doesn't crash
        $component->set('ownerFilter', 11)
            ->assertSuccessful();
    }

    public function test_queue_options_are_sorted_alphabetically()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        ScheduledZnunyTask::create([
            'name' => 'Task Z',
            'enabled' => false,
            'queue_name' => 'zulu',
        ]);
        ScheduledZnunyTask::create([
            'name' => 'Task A',
            'enabled' => false,
            'queue_name' => 'Alpha',
        ]);
        ScheduledZnunyTask::create([
            'name' => 'Task B',
            'enabled' => false,
            'queue_name' => 'bravo',
        ]);
        ScheduledZnunyTask::create([
            'name' => 'Task M',
            'enabled' => false,
            'queue_name' => 'Media Holding',
        ]);

        $component = Livewire::test(ListScheduledZnunyTasks::class);

        $options = $component->instance()->getQueueOptions();

        $expected = ['Alpha', 'bravo', 'Media Holding', 'zulu'];

        $this->assertEquals($expected, array_keys($options));
        $this->assertEquals($expected, array_values($options));

        $component->set('queueFilter', 'Media Holding')
            ->assertSuccessful();
    }

    public function test_owner_filter_correctly_filters_by_owner_id()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $task1 = ScheduledZnunyTask::create([
            'name' => 'Task Owner 2',
            'enabled' => false,
            'owner_id' => 2,
        ]);

        $task2 = ScheduledZnunyTask::create([
            'name' => 'Task Owner 3',
            'enabled' => false,
            'owner_id' => 3,
        ]);

        $component = Livewire::test(ListScheduledZnunyTasks::class);

        // Without filter, both are visible
        $component->assertCanSeeTableRecords([$task1, $task2]);

        // Filter by string "2"
        $component->set('ownerFilter', '2')
            ->assertCanSeeTableRecords([$task1])
            ->assertCanNotSeeTableRecords([$task2]);

        // Filter by int 3
        $component->set('ownerFilter', 3)
            ->assertCanSeeTableRecords([$task2])
            ->assertCanNotSeeTableRecords([$task1]);

        // Clear filter ("")
        $component->set('ownerFilter', '')
            ->assertCanSeeTableRecords([$task1, $task2]);

        // Clear filter ("all")
        $component->set('ownerFilter', 'all')
            ->assertCanSeeTableRecords([$task1, $task2]);
    }

    public function test_scheduler_status_console_renders_dark_theme_safe_alert_banner()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        SystemAlert::create([
            'source' => 'scheduler',
            'status' => 'active',
            'severity' => 'warning',
            'title' => 'Scheduler Paused (Not Sent)',
            'message' => "Task 'Mikrotik' paused the scheduler. Pre-flight/Local check failed.",
        ]);

        // Default: Scheduler is enabled. Alert should NOT be visible on main panel.
        Livewire::test(SchedulerStatusConsole::class)
            ->assertSee('Enabled')
            ->assertDontSee('Scheduler Paused (Not Sent)')
            ->assertDontSee("Task 'Mikrotik' paused the scheduler.");

        // Now manually disable the scheduler. The historical alert should become visible.
        $this->artisan('app:ensure-settings-defaults');
        app(SchedulerSafetyService::class)->disableScheduler('test');
        SettingsService::clearAllCaches();

        Livewire::test(SchedulerStatusConsole::class)
            ->assertSee('Disabled')
            ->assertSee('Scheduler Paused (Not Sent)')
            ->assertSee("Task 'Mikrotik' paused the scheduler.")
            // Verify new subdued classes exist
            ->assertSee('bg-amber-50')
            ->assertSee('text-amber-900')
            ->assertSee('dark:bg-gray-800/50')
            ->assertSee('dark:border-gray-700/50')
            ->assertSee('dark:border-l-amber-500')
            ->assertSee('dark:text-gray-200')
            // Verify glowing / highly saturated amber classes are absent
            ->assertDontSee('dark:bg-amber-950/50', false)
            ->assertDontSee('dark:border-amber-700/50', false)
            // Verify truncate is still removed
            ->assertDontSee('class="truncate"', false);
    }
}
