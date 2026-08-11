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

    private function getRebuildEvent(): ?Event
    {
        $schedule = app(Schedule::class);
        return collect($schedule->events())->first(function (Event $event) {
            return str_contains($event->command ?? '', 'owner-suggestion:rebuild-stats');
        });
    }

    public function test_scheduler_has_event_configured_correctly()
    {
        $event = $this->getRebuildEvent();

        $this->assertNotNull($event, 'Rebuild stats command is not scheduled');
        $this->assertEquals('* * * * *', $event->expression, 'Should be scheduled every minute');
        $this->assertTrue($event->withoutOverlapping, 'Should be configured without overlapping');
    }

    public function test_due_on_first_run_when_cache_is_missing()
    {
        Cache::forget('owner_suggestion_last_rebuild_at');

        $event = $this->getRebuildEvent();
        
        Carbon::setTestNow(Carbon::now());

        $this->assertTrue($event->filtersPass($this->app), 'Should be due on first run when cache is missing');
    }

    public function test_skips_when_inside_interval()
    {
        Setting::updateOrCreate(['key' => 'owner_suggestion_rebuild_interval_minutes'], ['value' => '180']);
        SettingsService::clearAllCaches();

        $event = $this->getRebuildEvent();
        
        $now = Carbon::now();
        Carbon::setTestNow($now);

        Cache::put('owner_suggestion_last_rebuild_at', clone $now->subMinutes(179)->timestamp);
        
        Carbon::setTestNow($now);

        $this->assertFalse($event->filtersPass($this->app), 'Should skip when inside interval');
    }

    public function test_due_when_at_or_after_interval()
    {
        Setting::updateOrCreate(['key' => 'owner_suggestion_rebuild_interval_minutes'], ['value' => '180']);
        SettingsService::clearAllCaches();

        $event = $this->getRebuildEvent();
        
        $now = Carbon::now();
        Carbon::setTestNow($now);

        // Exactly at interval
        Cache::put('owner_suggestion_last_rebuild_at', clone $now->subMinutes(180)->timestamp);
        Carbon::setTestNow($now);
        $this->assertTrue($event->filtersPass($this->app), 'Should be due exactly at interval');

        // After interval
        Cache::put('owner_suggestion_last_rebuild_at', clone $now->subMinutes(181)->timestamp);
        Carbon::setTestNow($now);
        $this->assertTrue($event->filtersPass($this->app), 'Should be due after interval');
    }
}
