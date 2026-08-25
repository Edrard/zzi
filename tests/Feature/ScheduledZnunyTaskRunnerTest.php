<?php

namespace Tests\Feature;

use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Services\SchedulerSafetyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduledZnunyTaskRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('db:seed', ['--class' => 'SettingsSeeder']);
        app(SchedulerSafetyService::class)->enableScheduler();
    }

    public function test_runner_materializes_and_processes_pending_runs_with_not_sent_outcome(): void
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test Task',
            'cron_expression' => '* * * * *',
            'enabled' => true,
            'next_run_at' => now()->subMinute(),
        ]);

        $this->assertEquals(0, ScheduledZnunyTaskRun::count());

        Artisan::call('scheduled-znuny:run');

        // It should have created pending runs (catch-up), tried to process them,
        // failed local validation (NOT_SENT), marked them as failed, and DID NOT pause the scheduler.
        $this->assertEquals(2, ScheduledZnunyTaskRun::count());

        $runs = ScheduledZnunyTaskRun::orderBy('id')->get();
        foreach ($runs as $run) {
            $this->assertEquals('failed', $run->status);
            $this->assertNotNull($run->finished_at);
            $this->assertNotNull($run->error_summary);
        }

        $this->assertFalse(app(SchedulerSafetyService::class)->isSchedulerPaused());
    }

    public function test_scheduler_obeys_global_disabled_flag_materializing_but_not_processing(): void
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test Task',
            'cron_expression' => '* * * * *',
            'enabled' => true,
            'next_run_at' => now()->subMinute(),
        ]);

        app(SchedulerSafetyService::class)->disableScheduler('Test Disable');

        Artisan::call('scheduled-znuny:run');

        // Disabled = materialization happens (catch-up = 2 runs), but they stay pending
        $this->assertEquals(2, ScheduledZnunyTaskRun::count());
        $this->assertEquals('pending', ScheduledZnunyTaskRun::first()->status);
    }

    public function test_scheduler_obeys_paused_state_materializing_but_not_processing(): void
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test Task',
            'cron_expression' => '* * * * *',
            'enabled' => true,
            'next_run_at' => now()->subMinute(),
        ]);

        app(SchedulerSafetyService::class)->pauseScheduler('Test Pause');

        Artisan::call('scheduled-znuny:run');

        // Paused = materialization happens (catch-up = 2 runs), but they stay pending
        $this->assertEquals(2, ScheduledZnunyTaskRun::count());
        $this->assertEquals('pending', ScheduledZnunyTaskRun::first()->status);
    }

    public function test_retention_service_cleans_up_old_runs(): void
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test Task',
            'cron_expression' => '* * * * *',
            'enabled' => true,
        ]);

        $oldRun = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'status' => 'skipped',
            'scheduled_for' => now()->subDays(200),
        ]);
        // Manually update created_at to bypass Eloquent timestamp management
        DB::table('scheduled_znuny_task_runs')
            ->where('id', $oldRun->id)
            ->update(['created_at' => now()->subDays(200)->toDateTimeString()]);

        $newRun = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'status' => 'skipped',
            'scheduled_for' => now(),
            'created_at' => now(),
        ]);

        Artisan::call('scheduled-znuny:cleanup-runs');

        $this->assertEquals(1, ScheduledZnunyTaskRun::count());
        $this->assertEquals($newRun->id, ScheduledZnunyTaskRun::first()->id);
    }

    public function test_runner_uses_global_lock_and_prevents_overlap(): void
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Test Task',
            'cron_expression' => '* * * * *',
            'enabled' => true,
            'next_run_at' => now()->subMinute(),
        ]);

        Cache::lock('scheduled_znuny_task_runner', 60)->get();

        Artisan::call('scheduled-znuny:run');

        // Because lock is held, it returns immediately without materializing
        $this->assertEquals(0, ScheduledZnunyTaskRun::count());

        // Release to clean up
        Cache::lock('scheduled_znuny_task_runner', 60)->forceRelease();
    }
}
