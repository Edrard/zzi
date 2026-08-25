<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

class ManualTicketAutoCloseScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function getScheduledEvents()
    {
        Cache::flush();
        SettingsService::clearAllCaches();
        app()->forgetInstance(Schedule::class);
        Facade::clearResolvedInstance(Schedule::class);
        $schedule = app(Schedule::class);

        // Load console routes to populate the schedule
        require base_path('routes/console.php');

        return collect($schedule->events());
    }

    public function test_scheduler_does_not_register_when_disabled()
    {
        Setting::updateOrCreate(['key' => 'manual_ticket_auto_close_schedule_mode'], ['value' => 'disabled']);

        $events = $this->getScheduledEvents()->filter(fn ($e) => str_contains($e->command, 'znuny:auto-close-manual-tickets'));

        $this->assertEmpty($events);
    }

    public function test_scheduler_registers_dry_run_mode()
    {
        Setting::updateOrCreate(['key' => 'manual_ticket_auto_close_schedule_mode'], ['value' => 'dry_run']);

        $events = $this->getScheduledEvents()->filter(fn ($e) => str_contains($e->command, 'znuny:auto-close-manual-tickets'));

        $this->assertCount(1, $events);
        $this->assertStringNotContainsString('--execute', $events->first()->command);
    }

    public function test_scheduler_registers_execute_mode()
    {
        Setting::updateOrCreate(['key' => 'manual_ticket_auto_close_schedule_mode'], ['value' => 'execute']);

        $events = $this->getScheduledEvents()->filter(fn ($e) => str_contains($e->command, 'znuny:auto-close-manual-tickets'));

        $this->assertCount(1, $events);
        $this->assertStringContainsString('--execute', $events->first()->command);
    }

    public function test_scheduler_fallback_interprets_old_boolean_true()
    {
        Setting::where('key', 'manual_ticket_auto_close_schedule_mode')->delete();
        Setting::updateOrCreate(['key' => 'manual_ticket_auto_close_enabled'], ['value' => 'true']);

        $events = $this->getScheduledEvents()->filter(fn ($e) => str_contains($e->command, 'znuny:auto-close-manual-tickets'));

        $this->assertCount(1, $events);
        $this->assertStringContainsString('--execute', $events->first()->command);
    }

    public function test_scheduler_fallback_interprets_old_boolean_false()
    {
        Setting::where('key', 'manual_ticket_auto_close_schedule_mode')->delete();
        Setting::updateOrCreate(['key' => 'manual_ticket_auto_close_enabled'], ['value' => 'false']);

        $events = $this->getScheduledEvents()->filter(fn ($e) => str_contains($e->command, 'znuny:auto-close-manual-tickets'));

        $this->assertEmpty($events);
    }
}
