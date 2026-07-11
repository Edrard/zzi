<?php

namespace App\Services\Cron;

use Carbon\Carbon;
use Cron\CronExpression;

class CronService
{
    /**
     * Validates if the given string is a valid 5-field cron expression.
     */
    public function isValid(string $expression): bool
    {
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) {
            return false;
        }

        try {
            return CronExpression::isValidExpression($expression);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Calculates the next run date for a given cron expression.
     */
    public function calculateNextRun(string $expression, ?string $timezone = null): ?Carbon
    {
        return $this->calculateNextRunFrom($expression, now(), $timezone);
    }

    public function calculateNextRunFrom(string $expression, \DateTimeInterface|string $from, ?string $timezone = null): ?Carbon
    {
        if (! $this->isValid($expression)) {
            return null;
        }

        try {
            $cron = new CronExpression($expression);
            $tz = $timezone ?: config('app.timezone');
            $nextRun = $cron->getNextRunDate($from, 0, false, $tz);

            return Carbon::instance($nextRun)->setTimezone(config('app.timezone')); // Normalise to app timezone for DB
        } catch (\Throwable $e) {
            return null;
        }
    }
}
