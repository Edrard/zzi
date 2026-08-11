<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OwnerSuggestionRebuildScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Event>
     */
    private function getRebuildEvents()
    {
        $schedule = app(Schedule::class);

        return collect($schedule->events())->filter(function (Event $event) {
            return str_contains($event->command ?? '', 'owner-suggestion:rebuild-stats');
        });
    }

    public function test_scheduler_has_event_configured_correctly()
    {
        $events = $this->getRebuildEvents();

        $this->assertCount(1, $events, 'Rebuild stats command should be scheduled exactly once');

        $event = $events->first();
        $this->assertEquals('* * * * *', $event->expression, 'Should be scheduled every minute');
        $this->assertTrue($event->withoutOverlapping, 'Should be configured without overlapping');
    }

    public function test_due_on_first_run_when_cache_is_missing()
    {
        Cache::forget('owner_suggestion_last_rebuild_at');

        $event = $this->getRebuildEvents()->first();

        $now = Carbon::parse('2026-08-11 12:00:00');
        Carbon::setTestNow($now);

        $this->assertTrue($event->filtersPass($this->app), 'Should be due on first run when cache is missing');
    }

    public function test_skips_when_inside_interval()
    {
        Setting::updateOrCreate(['key' => 'owner_suggestion_rebuild_interval_minutes'], ['value' => '180']);
        SettingsService::clearAllCaches();

        $event = $this->getRebuildEvents()->first();

        $now = Carbon::parse('2026-08-11 12:00:00');
        Carbon::setTestNow($now);

        Cache::put(
            'owner_suggestion_last_rebuild_at',
            $now->copy()->subMinutes(179)->timestamp
        );

        $this->assertFalse($event->filtersPass($this->app), 'Should skip when inside interval');
    }

    public function test_due_when_at_or_after_interval()
    {
        Setting::updateOrCreate(['key' => 'owner_suggestion_rebuild_interval_minutes'], ['value' => '180']);
        SettingsService::clearAllCaches();

        $event = $this->getRebuildEvents()->first();

        $now = Carbon::parse('2026-08-11 12:00:00');
        Carbon::setTestNow($now);

        // Exactly at interval
        Cache::put(
            'owner_suggestion_last_rebuild_at',
            $now->copy()->subMinutes(180)->timestamp
        );
        $this->assertTrue($event->filtersPass($this->app), 'Should be due exactly at interval');

        // After interval
        Cache::put(
            'owner_suggestion_last_rebuild_at',
            $now->copy()->subMinutes(181)->timestamp
        );
        $this->assertTrue($event->filtersPass($this->app), 'Should be due after interval');
    }

    public function test_interval_setting_is_read_dynamically_at_execution_time()
    {
        // 1. obtain the scheduled event
        $event = $this->getRebuildEvents()->first();

        $now = Carbon::parse('2026-08-11 12:00:00');
        Carbon::setTestNow($now);

        // old/default interval = 180 => would skip if last run is 15 minutes ago

        // 2. then change owner_suggestion_rebuild_interval_minutes
        Setting::updateOrCreate(['key' => 'owner_suggestion_rebuild_interval_minutes'], ['value' => '10']);

        // 3. clear SettingsService caches using the existing public test-safe mechanism
        SettingsService::clearAllCaches();

        // 4. set a last-run timestamp that would produce a different result under the old and new interval
        Cache::put(
            'owner_suggestion_last_rebuild_at',
            $now->copy()->subMinutes(15)->timestamp
        );

        // 5. call filtersPass($this->app) and assert the NEW interval controls the result
        // new runtime interval = 10 => must run since 15 > 10
        $this->assertTrue($event->filtersPass($this->app), 'Should be due because dynamic interval is 10 and 15 minutes have passed');
    }
}
