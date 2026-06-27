<?php

namespace App\Support\Polling;

class UiPollInterval
{
    /**
     * Get the global UI poll interval in seconds.
     * Falls back to 60 if missing, invalid, or zero/negative.
     */
    public static function getSeconds(): int
    {
        $value = config('app.ui_poll_interval_seconds', 60);

        $seconds = filter_var($value, FILTER_VALIDATE_INT);

        if ($seconds === false || $seconds <= 0) {
            return 60;
        }

        return $seconds;
    }

    /**
     * Get the poll interval as a Livewire-friendly string (e.g., '60s').
     */
    public static function getLivewireString(): string
    {
        return self::getSeconds().'s';
    }
}
