<?php

namespace Tests\Feature\Scheduler;

use App\Filament\Resources\ScheduledZnunyTasks\Pages\CreateScheduledZnunyTask;
use App\Filament\Resources\ScheduledZnunyTasks\Pages\EditScheduledZnunyTask;
use App\Filament\Resources\ScheduledZnunyTasks\Pages\ListScheduledZnunyTasks;
use App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource;
use App\Filament\Resources\ScheduledZnunyTasks\Widgets\SchedulerStatusConsole;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\User;
use App\Services\Cron\CronService;
use App\Services\ScheduledZnunyTaskRunProcessor;
use App\Services\ScheduledZnunyTicketCreationService;
use App\Services\SchedulerSafetyService;
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
            ->assertNotified('Validation Error');

        $this->assertDatabaseHas('scheduled_znuny_tasks', [
            'id' => $task->id,
            'cron_expression' => '0 * * * *',
        ]);
    }

    public function test_empty_inline_queue_does_not_overwrite_existing()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $task = ScheduledZnunyTask::create([
            'name' => 'Valid Task',
            'enabled' => false,
            'queue_name' => 'OriginalQueue',
        ]);

        $this->actingAs($admin);

        Livewire::test(ListScheduledZnunyTasks::class)
            ->call('updateTableColumnState', 'queue_name', $task->id, '')
            ->assertNotified('Validation Error');

        $this->assertDatabaseHas('scheduled_znuny_tasks', [
            'id' => $task->id,
            'queue_name' => 'OriginalQueue',
        ]);
    }

    public function test_empty_inline_owner_does_not_overwrite_existing()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $task = ScheduledZnunyTask::create([
            'name' => 'Valid Task',
            'enabled' => false,
            'owner_login' => 'original.owner',
        ]);

        $this->actingAs($admin);

        Livewire::test(ListScheduledZnunyTasks::class)
            ->call('updateTableColumnState', 'owner_login', $task->id, '')
            ->assertNotified('Validation Error');

        $this->assertDatabaseHas('scheduled_znuny_tasks', [
            'id' => $task->id,
            'owner_login' => 'original.owner',
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
            ->assertNotified('Run Queued');

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
            ->assertNotified('Run Queued');

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
                'owner_login' => null,
                'customer_user_login' => null,
                'subject' => null,
                'body' => null,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'cron_expression' => 'required',
                'timezone' => 'required',
                'queue_name' => 'required',
                'owner_login' => 'required',
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

    public function test_next_run_at_is_calculated_and_displayed_in_list()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        Carbon::setTestNow('2026-07-09 10:00:00'); // 10:00 UTC = 13:00 Kyiv

        $task1 = ScheduledZnunyTask::create([
            'name' => 'Task Valid',
            'enabled' => false,
            'cron_expression' => '0 15 * * *',
            'timezone' => 'Europe/Kyiv',
            'next_run_at' => null,
        ]);

        $task2 = ScheduledZnunyTask::create([
            'name' => 'Task Invalid',
            'enabled' => false,
            'cron_expression' => 'invalid',
            'timezone' => 'UTC',
            'next_run_at' => null,
        ]);

        $component = Livewire::test(ListScheduledZnunyTasks::class);

        $component->assertTableColumnStateSet('next_run_at', '2026-07-10 15:00:00 Europe/Kyiv', record: $task1);
        $component->assertTableColumnStateSet('next_run_at', null, record: $task2);

        // Ensure the placeholder is visible
        $component->assertSee('Not calculated');
        $component->assertSee('2026-07-10 15:00:00 Europe/Kyiv');

        Carbon::setTestNow();
    }

    public function test_headers_render_sort_buttons()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $component = Livewire::test(ListScheduledZnunyTasks::class);

        $html = $component->html();

        // Confirm browser does NOT render clickable server-sort action on headers
        $this->assertStringNotContainsString('wire:click="sortTable(', $html);

        // Confirm the client side sorting script is loaded
        $this->assertStringContainsString('scheduledTasksClientSort', $html);

        // Confirm Customer User column exists
        $this->assertStringContainsString('Customer User', $html);
    }

    public function test_inline_queue_update_resets_owner_and_resolves_customer_user_candidate()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $task = ScheduledZnunyTask::create([
            'name' => 'Valid Task',
            'enabled' => false,
            'queue_name' => 'OldQueue',
            'owner_login' => 'old.owner',
            'customer_user_login' => 'old.customer',
        ]);

        $mock = \Mockery::mock(ZnunyCachedLookupService::class)->makePartial();
        $mock->shouldReceive('getFilteredQueueOptions')->andReturn(['NewQueue' => 'NewQueue', 'OldQueue' => 'OldQueue']);
        $mock->shouldReceive('resolveTemplateCandidate')->with('NewQueue')->andReturn('new.customer');
        $this->app->instance(ZnunyCachedLookupService::class, $mock);

        Livewire::test(ListScheduledZnunyTasks::class)
            ->call('updateTableColumnState', 'queue_name', $task->id, 'NewQueue')
            ->assertSuccessful();

        $this->assertDatabaseHas('scheduled_znuny_tasks', [
            'id' => $task->id,
            'queue_name' => 'NewQueue',
            'owner_login' => null,
            'customer_user_login' => 'new.customer',
        ]);
    }

    public function test_inline_queue_update_resets_owner_and_nulls_customer_user_if_no_candidate()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $task = ScheduledZnunyTask::create([
            'name' => 'Valid Task',
            'enabled' => false,
            'queue_name' => 'OldQueue',
            'owner_login' => 'old.owner',
            'customer_user_login' => 'old.customer',
        ]);

        $mock = \Mockery::mock(ZnunyCachedLookupService::class)->makePartial();
        $mock->shouldReceive('getFilteredQueueOptions')->andReturn(['NewQueue' => 'NewQueue', 'OldQueue' => 'OldQueue']);
        $mock->shouldReceive('resolveTemplateCandidate')->with('NewQueue')->andReturn(null);
        $this->app->instance(ZnunyCachedLookupService::class, $mock);

        $component = Livewire::test(ListScheduledZnunyTasks::class)
            ->call('updateTableColumnState', 'queue_name', $task->id, 'NewQueue')
            ->assertSuccessful();

        $this->assertDatabaseHas('scheduled_znuny_tasks', [
            'id' => $task->id,
            'queue_name' => 'NewQueue',
            'owner_login' => null,
            'customer_user_login' => null,
        ]);
    }

    public function test_manual_inline_customer_user_update_saves_and_does_not_reset_queue()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $task = ScheduledZnunyTask::create([
            'name' => 'Valid Task',
            'enabled' => false,
            'queue_name' => 'QueueName',
            'owner_login' => 'owner.name',
            'customer_user_login' => null,
        ]);

        $mock = \Mockery::mock(ZnunyCachedLookupService::class)->makePartial();
        $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->with('QueueName')->andReturn(['manual.customer' => 'Manual Customer']);
        $this->app->instance(ZnunyCachedLookupService::class, $mock);

        Livewire::test(ListScheduledZnunyTasks::class)
            ->call('updateTableColumnState', 'customer_user_login', $task->id, 'manual.customer')
            ->assertSuccessful();

        $this->assertDatabaseHas('scheduled_znuny_tasks', [
            'id' => $task->id,
            'queue_name' => 'QueueName', // Not reset
            'owner_login' => 'owner.name', // Not reset
            'customer_user_login' => 'manual.customer',
        ]);
    }
}
