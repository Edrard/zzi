<?php

namespace App\Services\Zabbix;

use Carbon\Carbon;

class ZabbixProblemFormatter
{
    /**
     * Calculate the age of a problem in seconds.
     */
    public function getProblemAgeSeconds(array $problem): int
    {
        if (! empty($problem['clock'])) {
            return max(0, time() - (int) $problem['clock']);
        }

        if (! empty($problem['started_at'])) {
            try {
                return max(0, Carbon::parse($problem['started_at'])->diffInSeconds(now()));
            } catch (\Exception $e) {
                // fall through
            }
        }

        if (isset($problem['age_seconds'])) {
            return (int) $problem['age_seconds'];
        }

        return 0;
    }

    /**
     * Format a given number of seconds into a human-readable string (e.g., "2d 4h", "15m").
     */
    public function formatAge(int $seconds): string
    {
        if ($seconds < 60) {
            return '<1m';
        }

        $minutes = floor($seconds / 60);
        $hours = floor($minutes / 60);
        $days = floor($hours / 24);

        $parts = [];

        if ($days > 0) {
            $parts[] = "{$days}d";
            $hours %= 24;
            if ($hours > 0) {
                $parts[] = "{$hours}h";
            }
        } elseif ($hours > 0) {
            $parts[] = "{$hours}h";
            $minutes %= 60;
            if ($minutes > 0) {
                $parts[] = "{$minutes}m";
            }
        } else {
            $parts[] = "{$minutes}m";
        }

        return implode(' ', $parts);
    }

    /**
     * Get the UI color associated with a Zabbix severity level.
     */
    public function getSeverityColor(int $severity): string
    {
        return match ($severity) {
            0 => 'gray',
            1 => 'info',
            2 => 'warning',
            3 => 'warning',
            4 => 'danger',
            5 => 'danger',
            default => 'gray',
        };
    }

    /**
     * Get the fallback text label for a Zabbix severity level.
     */
    public function getSeverityFallback(int $severity): string
    {
        return match ($severity) {
            0 => 'Not classified',
            1 => 'Information',
            2 => 'Warning',
            3 => 'Average',
            4 => 'High',
            5 => 'Disaster',
            default => 'Unknown',
        };
    }
}
