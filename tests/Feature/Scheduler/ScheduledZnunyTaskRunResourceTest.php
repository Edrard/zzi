<?php

namespace Tests\Feature\Scheduler;

use App\Filament\Resources\ScheduledZnunyTaskRuns\Pages\ManageScheduledZnunyTaskRuns;
use App\Filament\Resources\ScheduledZnunyTaskRuns\ScheduledZnunyTaskRunResource;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\User;
use App\Services\ScheduledZnunyTicketCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class ScheduledZnunyTaskRunResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('db:seed', ['--class' => 'SettingsSeeder']);
    }

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

    public function test_scheduler_log_does_not_have_retry_action()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        Livewire::test(ManageScheduledZnunyTaskRuns::class)
            ->assertTableActionDoesNotExist('retry_pending_run');
    }

    public function test_requeue_failed_creates_a_new_pending_run()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $task = ScheduledZnunyTask::create([
            'name' => 'Test Task',
            'enabled' => false,
        ]);

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'manual',
            'scheduled_for' => now()->subDay(),
            'status' => 'failed',
        ]);

        $mockService = $this->createMock(ScheduledZnunyTicketCreationService::class);
        $mockService->expects($this->never())->method('createTicketFromTask');
        $this->app->instance(ScheduledZnunyTicketCreationService::class, $mockService);

        Livewire::test(ManageScheduledZnunyTaskRuns::class)
            ->assertTableActionExists('requeue_failed_run')
            ->callTableAction('requeue_failed_run', $run)
            ->assertNotified();

        $this->assertEquals('failed', $run->fresh()->status);

        $newRun = ScheduledZnunyTaskRun::where('id', '>', $run->id)->first();
        $this->assertNotNull($newRun);
        $this->assertEquals('pending', $newRun->status);
        $this->assertEquals('manual_retry', $newRun->run_type);
        $this->assertEquals($run->task_name_snapshot, $newRun->task_name_snapshot);
    }

    public function test_resolve_uncertain_run_updates_status_to_skipped()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $run = ScheduledZnunyTaskRun::create([
            'task_name_snapshot' => 'Test',
            'run_type' => 'manual',
            'scheduled_for' => now(),
            'status' => 'uncertain',
            'error_details' => 'Initial error',
        ]);

        $mockService = $this->createMock(ScheduledZnunyTicketCreationService::class);
        $mockService->expects($this->never())->method('createTicketFromTask');
        $this->app->instance(ScheduledZnunyTicketCreationService::class, $mockService);

        Livewire::test(ManageScheduledZnunyTaskRuns::class)
            ->assertTableActionExists('resolve_uncertain_run')
            ->callTableAction('resolve_uncertain_run', $run, data: [
                'note' => 'I checked Znuny, no ticket created.',
            ])
            ->assertNotified();

        $run->refresh();
        $this->assertEquals('skipped', $run->status);
        $this->assertStringContainsString('Uncertain run manually reviewed', $run->error_summary);
        $this->assertStringContainsString('I checked Znuny, no ticket created.', $run->error_details);
        $this->assertStringContainsString('Initial error', $run->error_details);
    }

    public function test_open_ticket_action_only_visible_with_ticket_id()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $runWithId = ScheduledZnunyTaskRun::create([
            'task_name_snapshot' => 'Test',
            'run_type' => 'manual',
            'scheduled_for' => now(),
            'status' => 'success',
            'ticket_id' => 12345,
            'ticket_number' => '12345',
        ]);

        $runWithNumberOnly = ScheduledZnunyTaskRun::create([
            'task_name_snapshot' => 'Test',
            'run_type' => 'manual',
            'scheduled_for' => now(),
            'status' => 'success',
            'ticket_id' => null,
            'ticket_number' => '12345',
        ]);

        Livewire::test(ManageScheduledZnunyTaskRuns::class)
            ->assertTableActionVisible('open_ticket', $runWithId)
            ->assertTableActionHidden('open_ticket', $runWithNumberOnly);
    }
}
