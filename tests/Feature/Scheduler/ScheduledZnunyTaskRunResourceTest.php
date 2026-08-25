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
            ->assertTableActionHidden('view', $run)
            ->mountTableAction('view', $run)
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

        Livewire::test(\App\Filament\Resources\ScheduledZnunyTaskRuns\Pages\ReviewScheduledZnunyTaskRunAttempt::class, ['record' => $run->id])
            ->assertActionExists('manual_retry')
            ->callAction('manual_retry')
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

        $task = ScheduledZnunyTask::create([
            'name' => 'Test Task',
            'enabled' => false,
        ]);

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'manual',
            'scheduled_for' => now(),
            'status' => 'uncertain',
            'error_details' => 'Initial error',
        ]);

        $attempt = \App\Models\ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $run->id,
            'marker' => 'M_'.$run->id,
            'status' => \App\Enums\ZnunyTicketCreationAttemptStatus::Uncertain->value,
            'subject_original' => 'Subject',
            'body_original' => 'Body',
            'subject_sent' => 'Subject',
            'body_sent' => 'Body',
        ]);

        Livewire::test(
            \App\Filament\Resources\ScheduledZnunyTaskRuns\Pages\ReviewScheduledZnunyTaskRunAttempt::class,
            ['record' => $run->id]
        )
            ->assertActionExists('manual_close')
            ->callAction('manual_close')
            ->assertNotified(__('scheduled_znuny_task_runs.review.notifications.manual_close_success.title'));

        $run->refresh();
        $attempt->refresh();
        $task->refresh();

        $this->assertEquals('uncertain', $run->status);
        $this->assertEquals('manual_closed', $run->resolution_type);
        $this->assertNotNull($run->resolved_at);
        $this->assertEquals(
            \App\Enums\ZnunyTicketCreationAttemptStatus::ResolvedWithoutTicket,
            $attempt->status
        );
        $this->assertEquals('success', $task->last_status);
        $this->assertNull($task->last_error_summary);
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
