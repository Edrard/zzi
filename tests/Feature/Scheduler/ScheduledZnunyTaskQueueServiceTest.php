<?php

namespace Tests\Feature\Scheduler;

use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\Setting;
use App\Services\ScheduledZnunyTaskQueueService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ScheduledZnunyTaskQueueServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('db:seed', ['--class' => 'SettingsSeeder']);

        Setting::updateOrCreate(
            ['key' => 'scheduled_tasks_catchup_batch_limit'],
            ['value' => 50, 'type' => 'int']
        );
        SettingsService::clearAllCaches();
    }

    public function test_yearly_timezone_catchup()
    {
        Carbon::setTestNow('2026-07-12 11:00:00'); // 11:00 UTC

        $task = ScheduledZnunyTask::create([
            'name' => 'Yearly Test',
            'enabled' => true,
            'cron_expression' => '27 13 12 7 *',
            'timezone' => 'Europe/Kyiv',
            'next_run_at' => '2026-07-12 10:27:00', // 10:27 UTC = 13:27 Kyiv
        ]);

        $service = app(ScheduledZnunyTaskQueueService::class);
        $count = $service->materializePendingRuns();

        $this->assertEquals(1, $count);
        $task->refresh();
        $this->assertEquals('2027-07-12 10:27:00', $task->next_run_at->format('Y-m-d H:i:s'));

        $runs = ScheduledZnunyTaskRun::all();
        $this->assertCount(1, $runs);
        $this->assertEquals('2026-07-12 10:27:00', $runs->first()->scheduled_for->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_catchup_generation()
    {
        Carbon::setTestNow('2026-07-12 13:30:00'); // 13:30 UTC

        $task = ScheduledZnunyTask::create([
            'name' => 'Hourly Test',
            'enabled' => true,
            'cron_expression' => '0 * * * *',
            'timezone' => 'UTC',
            'next_run_at' => '2026-07-12 10:00:00',
        ]);

        $service = app(ScheduledZnunyTaskQueueService::class);
        $count = $service->materializePendingRuns();

        $this->assertEquals(4, $count);
        $task->refresh();
        $this->assertEquals('2026-07-12 14:00:00', $task->next_run_at->format('Y-m-d H:i:s'));

        $runs = ScheduledZnunyTaskRun::orderBy('scheduled_for')->pluck('scheduled_for')->map(fn ($d) => $d->format('H:i'))->toArray();
        $this->assertEquals(['10:00', '11:00', '12:00', '13:00'], $runs);

        Carbon::setTestNow();
    }

    public function test_catchup_limit()
    {
        Setting::updateOrCreate(
            ['key' => 'scheduled_tasks_catchup_batch_limit'],
            ['value' => 3, 'type' => 'int']
        );
        SettingsService::clearAllCaches();

        Carbon::setTestNow('2026-07-12 15:30:00'); // 15:30 UTC

        $task = ScheduledZnunyTask::create([
            'name' => 'Hourly Test Limit',
            'enabled' => true,
            'cron_expression' => '0 * * * *',
            'timezone' => 'UTC',
            'next_run_at' => '2026-07-12 10:00:00',
        ]);

        $service = app(ScheduledZnunyTaskQueueService::class);
        $count = $service->materializePendingRuns();

        // Limit is 3, so it should generate 10:00, 11:00, 12:00
        $this->assertEquals(3, $count);
        $task->refresh();
        $this->assertEquals('2026-07-12 13:00:00', $task->next_run_at->format('Y-m-d H:i:s'));

        $runs = ScheduledZnunyTaskRun::orderBy('scheduled_for')->pluck('scheduled_for')->map(fn ($d) => $d->format('H:i'))->toArray();
        $this->assertEquals(['10:00', '11:00', '12:00'], $runs);

        Carbon::setTestNow();
    }

    public function test_no_duplicate_scheduled_for()
    {
        Carbon::setTestNow('2026-07-12 11:30:00'); // 11:30 UTC

        $task = ScheduledZnunyTask::create([
            'name' => 'Duplicate Test',
            'enabled' => true,
            'cron_expression' => '0 * * * *',
            'timezone' => 'UTC',
            'next_run_at' => '2026-07-12 10:00:00',
        ]);

        // Manually create the 10:00 run to simulate it being previously generated
        ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'status' => 'pending',
            'scheduled_for' => '2026-07-12 10:00:00',
        ]);

        $service = app(ScheduledZnunyTaskQueueService::class);
        $count = $service->materializePendingRuns();

        // 10:00 already exists, should only create 11:00
        $this->assertEquals(1, $count);
        $task->refresh();
        $this->assertEquals('2026-07-12 12:00:00', $task->next_run_at->format('Y-m-d H:i:s'));

        $runs = ScheduledZnunyTaskRun::orderBy('scheduled_for')->get();
        $this->assertCount(2, $runs);

        Carbon::setTestNow();
    }

    public function test_normalization_for_stale_yearly_task()
    {
        Carbon::setTestNow('2026-07-12 11:30:00'); // 11:30 UTC

        // Create the task with the stale local-masquerading-as-UTC next_run_at value
        $task = ScheduledZnunyTask::create([
            'name' => 'Stale Yearly Test',
            'enabled' => true,
            'cron_expression' => '27 13 12 7 *',
            'timezone' => 'Europe/Kyiv',
            'next_run_at' => '2026-07-12 13:27:00', // Stale! Was saved as local instead of 10:27 UTC
            'last_run_at' => '2026-07-12 10:27:05', // Last run occurred just after 10:27 UTC
        ]);

        // Manually create the consumed run
        ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'status' => 'success',
            'scheduled_for' => '2026-07-12 10:27:00', // 10:27 UTC = 13:27 Kyiv
        ]);

        $service = app(ScheduledZnunyTaskQueueService::class);
        $count = $service->materializePendingRuns(); // Triggers normalization

        $task->refresh();
        $this->assertEquals('2027-07-12 10:27:00', $task->next_run_at->format('Y-m-d H:i:s')); // Now properly normalized to UTC next year!
        $this->assertEquals(0, $count); // No runs generated because 2027 is in the future

        Carbon::setTestNow();
    }
}
