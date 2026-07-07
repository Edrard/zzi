<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SchedulerSafetyService
{
    public function isSchedulerEnabled(): bool
    {
        return SettingsService::bool('scheduled_tasks_enabled', true);
    }

    public function isSchedulerPaused(): bool
    {
        $pausedUntilStr = SettingsService::string('scheduled_tasks_paused_until');
        if (! $pausedUntilStr) {
            return false;
        }

        $pausedUntil = Carbon::parse($pausedUntilStr);
        if ($pausedUntil->isFuture()) {
            return true;
        }

        // Auto-clear pause if it has expired to avoid stale data
        $this->clearPause();

        return false;
    }

    public function pauseScheduler(string $reason, ?int $minutes = null): void
    {
        $minutes = $minutes ?? SettingsService::int('scheduled_tasks_pause_minutes', 30);
        $pausedUntil = now()->addMinutes($minutes);

        $setting = Setting::where('key', 'scheduled_tasks_paused_until')->first();
        if ($setting) {
            $setting->update(['value' => $pausedUntil->toIso8601String()]);
        }

        $settingReason = Setting::where('key', 'scheduled_tasks_pause_reason')->first();
        if ($settingReason) {
            $settingReason->update(['value' => $reason]);
        }

        Log::info("Scheduler paused until {$pausedUntil} for reason: {$reason}");
    }

    public function clearPause(): void
    {
        $setting = Setting::where('key', 'scheduled_tasks_paused_until')->first();
        if ($setting) {
            $setting->update(['value' => null]);
        }

        $settingReason = Setting::where('key', 'scheduled_tasks_pause_reason')->first();
        if ($settingReason) {
            $settingReason->update(['value' => null]);
        }

        Log::info('Scheduler pause cleared.');
    }

    public function disableScheduler(string $reason): void
    {
        $setting = Setting::where('key', 'scheduled_tasks_enabled')->first();
        if ($setting) {
            $setting->update(['value' => 'false']);
        }

        $settingReason = Setting::where('key', 'scheduled_tasks_disabled_reason')->first();
        if ($settingReason) {
            $settingReason->update(['value' => $reason]);
        }

        Log::warning("Scheduler globally disabled for reason: {$reason}");
    }

    public function enableScheduler(): void
    {
        $setting = Setting::where('key', 'scheduled_tasks_enabled')->first();
        if ($setting) {
            $setting->update(['value' => 'true']);
        }

        $settingReason = Setting::where('key', 'scheduled_tasks_disabled_reason')->first();
        if ($settingReason) {
            $settingReason->update(['value' => null]);
        }

        $this->clearPause();

        Log::info('Scheduler globally enabled.');
    }
}
