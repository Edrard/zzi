<?php

namespace Tests\Feature\Scheduler;

use App\Filament\Resources\ScheduledZnunyTaskRuns\Pages\ManageScheduledZnunyTaskRuns;
use App\Filament\Resources\ScheduledZnunyTaskRuns\ScheduledZnunyTaskRunResource;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ScheduledZnunyTaskRunResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_scheduler_log()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get(ScheduledZnunyTaskRunResource::getUrl('index'))
            ->assertSuccessful();
    }

    public function test_operator_cannot_access_scheduler_log()
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $this->actingAs($operator)
            ->get(ScheduledZnunyTaskRunResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_viewer_cannot_access_scheduler_log()
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)
            ->get(ScheduledZnunyTaskRunResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_scheduler_log_is_read_only_with_no_create_action()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin);

        Livewire::test(ManageScheduledZnunyTaskRuns::class)
            ->assertActionDoesNotExist('create')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');
    }

    public function test_scheduler_log_can_view_existing_run()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $task = ScheduledZnunyTask::create([
            'name' => 'Test Task',
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

        Livewire::test(ManageScheduledZnunyTaskRuns::class)
            ->assertTableActionExists('view')
            ->callTableAction('view', $run)
            ->assertSuccessful();
    }
}
