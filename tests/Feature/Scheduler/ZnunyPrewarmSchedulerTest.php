<?php

namespace Tests\Feature\Scheduler;

use App\Services\SettingsService;
use App\Services\Znuny\Cache\PrewarmRunnerService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\CallbackEvent;
use Mockery;
use Tests\TestCase;
use Carbon\Carbon;

class ZnunyPrewarmSchedulerTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function getPrewarmEvents()
    {
        $schedule = app(Schedule::class);
        $events = collect($schedule->events())->filter(function (Event $event) {
            return $event instanceof CallbackEvent
                && is_string($event->description)
                && str_starts_with($event->description, 'znuny-prewarm-');
        });
        return $events;
    }

    public function test_scheduler_has_exactly_four_callbacks_for_prewarm_with_correct_settings()
    {
        $events = $this->getPrewarmEvents();
        $this->assertCount(4, $events);

        $names = $events->pluck('description')->toArray();
        $this->assertContains('znuny-prewarm-queues', $names);
        $this->assertContains('znuny-prewarm-agents', $names);
        $this->assertContains('znuny-prewarm-customer_users', $names);
        $this->assertContains('znuny-prewarm-lookups', $names);

        foreach ($events as $event) {
            $this->assertEquals('* * * * *', $event->expression);
            $this->assertFalse($event->withoutOverlapping);
        }
    }

    public function test_scheduler_due_logic_and_intervals()
    {
        \App\Models\Setting::updateOrCreate(['key' => 'znuny_prewarm_queues_interval_minutes'], ['value' => 5, 'type' => 'integer']);
        \App\Services\SettingsService::clearAllCaches();
        \App\Models\Setting::updateOrCreate(['key' => 'znuny_prewarm_agents_interval_minutes'], ['value' => 30, 'type' => 'integer']);
        \App\Models\Setting::updateOrCreate(['key' => 'znuny_prewarm_customer_users_interval_minutes'], ['value' => 60, 'type' => 'integer']);
        \App\Models\Setting::updateOrCreate(['key' => 'znuny_prewarm_lookups_interval_minutes'], ['value' => 70, 'type' => 'integer']);
        \App\Services\SettingsService::clearAllCaches();

        $events = $this->getPrewarmEvents()->keyBy('description');

        $runnerMock = Mockery::mock(PrewarmRunnerService::class);
        $this->instance(PrewarmRunnerService::class, $runnerMock);

        Carbon::setTestNow(Carbon::createFromTimestamp(0));
        $runnerMock->shouldReceive('run')->with('queues', 'scheduler')->once();
        $runnerMock->shouldReceive('run')->with('agents', 'scheduler')->once();
        $runnerMock->shouldReceive('run')->with('customer_users', 'scheduler')->once();
        $runnerMock->shouldReceive('run')->with('lookups', 'scheduler')->once();

        foreach ($events as $event) {
            $event->run($this->app);
        }

        Carbon::setTestNow(Carbon::createFromTimestamp(300));
        $runnerMock->shouldReceive('run')->with('queues', 'scheduler')->once();
        foreach ($events as $event) {
            $event->run($this->app);
        }

        Carbon::setTestNow(Carbon::createFromTimestamp(1800));
        $runnerMock->shouldReceive('run')->with('queues', 'scheduler')->once();
        $runnerMock->shouldReceive('run')->with('agents', 'scheduler')->once();
        foreach ($events as $event) {
            $event->run($this->app);
        }

        Carbon::setTestNow(Carbon::createFromTimestamp(3600));
        $runnerMock->shouldReceive('run')->with('queues', 'scheduler')->once();
        $runnerMock->shouldReceive('run')->with('agents', 'scheduler')->once();
        $runnerMock->shouldReceive('run')->with('customer_users', 'scheduler')->once();
        foreach ($events as $event) {
            $event->run($this->app);
        }

        Carbon::setTestNow(Carbon::createFromTimestamp(4200));
        $runnerMock->shouldReceive('run')->with('queues', 'scheduler')->once();
        $runnerMock->shouldReceive('run')->with('lookups', 'scheduler')->once();
        foreach ($events as $event) {
            $event->run($this->app);
        }
    }

    public function test_scheduler_clamps_to_minimum_3()
    {
        \App\Models\Setting::updateOrCreate(['key' => 'znuny_prewarm_queues_interval_minutes'], ['value' => 1, 'type' => 'integer']);
        \App\Services\SettingsService::clearAllCaches();

        $events = $this->getPrewarmEvents()->keyBy('description');
        $queueEvent = $events['znuny-prewarm-queues'];

        $runnerMock = Mockery::mock(PrewarmRunnerService::class);
        $this->instance(PrewarmRunnerService::class, $runnerMock);

        Carbon::setTestNow(Carbon::createFromTimestamp(60));
        $queueEvent->run($this->app);

        Carbon::setTestNow(Carbon::createFromTimestamp(120));
        $queueEvent->run($this->app);

        Carbon::setTestNow(Carbon::createFromTimestamp(180));
        $runnerMock->shouldReceive('run')->with('queues', 'scheduler')->once();
        $queueEvent->run($this->app);
    }

    public function test_missed_tick_does_not_catch_up()
    {
        \App\Models\Setting::updateOrCreate(['key' => 'znuny_prewarm_queues_interval_minutes'], ['value' => 5, 'type' => 'integer']);
        \App\Services\SettingsService::clearAllCaches();

        $events = $this->getPrewarmEvents()->keyBy('description');
        $queueEvent = $events['znuny-prewarm-queues'];

        $runnerMock = Mockery::mock(PrewarmRunnerService::class);
        $this->instance(PrewarmRunnerService::class, $runnerMock);

        Carbon::setTestNow(Carbon::createFromTimestamp(360));
        $queueEvent->run($this->app);

        $this->assertTrue(true); // Should not throw Mockery exception because run() was not called
    }
}
