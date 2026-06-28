<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

class ClosedTicketSyncScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function getScheduledEvents()
    {
        Cache::flush();
        app()->forgetInstance(Schedule::class);
        Facade::clearResolvedInstance(Schedule::class);
        $schedule = app(Schedule::class);

        // Load console routes to populate the schedule
        require base_path('routes/console.php');

        return collect($schedule->events());
    }

    public function test_scheduler_registers_auto_sync_command()
    {
        $events = $this->getScheduledEvents()->filter(fn ($e) => str_contains($e->command, 'znuny:sync-closed-ticket-cache') && ! str_contains($e->command, '--full'));

        $this->assertCount(1, $events);
        $this->assertEquals('* * * * *', $events->first()->expression); // everyMinute
    }

    public function test_scheduler_registers_full_sync_command()
    {
        $events = $this->getScheduledEvents()->filter(fn ($e) => str_contains($e->command, 'znuny:sync-closed-ticket-cache --full'));

        $this->assertCount(1, $events);
        $this->assertEquals('30 2 * * *', $events->first()->expression); // dailyAt('02:30')
    }
}
