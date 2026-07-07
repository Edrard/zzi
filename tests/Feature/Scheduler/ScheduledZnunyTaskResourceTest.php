<?php

namespace Tests\Feature\Scheduler;

use App\Filament\Resources\ScheduledZnunyTasks\Pages\CreateScheduledZnunyTask;
use App\Filament\Resources\ScheduledZnunyTasks\Pages\EditScheduledZnunyTask;
use App\Filament\Resources\ScheduledZnunyTasks\Pages\ListScheduledZnunyTasks;
use App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\User;
use App\Services\Cron\CronService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ScheduledZnunyTaskResourceTest extends TestCase
{
    use RefreshDatabase;

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
}
