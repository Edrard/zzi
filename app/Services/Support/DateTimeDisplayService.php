<?php

namespace App\Services\Support;

use App\Services\SettingsService;
use Illuminate\Support\Carbon;

class DateTimeDisplayService
{
    public function timezone(): string
    {
        $tz = SettingsService::string('app_display_timezone', 'Europe/Kyiv');

        try {
            new \DateTimeZone($tz);

            return $tz;
        } catch (\Exception $e) {
            return 'UTC';
        }
    }

    public function formatDateTime(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            $date = Carbon::parse($value)->clone()->setTimezone($this->timezone());

            return $date->format('M j, Y H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    public function formatDateTimeWithTimezone(mixed $value): ?string
    {
        $formatted = $this->formatDateTime($value);
        if ($formatted) {
            return $formatted.' '.$this->timezone();
        }

        return null;
    }

    public function diffForHumans(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->diffForHumans();
        } catch (\Exception $e) {
            return null;
        }
    }
}
