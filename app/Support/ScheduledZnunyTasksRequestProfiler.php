<?php

namespace App\Support;

class ScheduledZnunyTasksRequestProfiler
{
    private bool $active = false;

    private array $durations = [];

    private array $counts = [];

    private int $dbQueryCount = 0;

    private float $dbCumulativeMs = 0.0;

    public function activate(): void
    {
        $this->active = true;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function measure(string $metric, \Closure $callback)
    {
        if (! $this->active) {
            return $callback();
        }

        $start = hrtime(true);
        try {
            return $callback();
        } finally {
            $end = hrtime(true);
            $ms = ($end - $start) / 1e6;

            $this->durations[$metric] = ($this->durations[$metric] ?? 0.0) + $ms;
            $this->counts[$metric] = ($this->counts[$metric] ?? 0) + 1;
        }
    }

    public function addDatabaseQuery(float $milliseconds): void
    {
        if (! $this->active) {
            return;
        }

        $this->dbQueryCount++;
        $this->dbCumulativeMs += $milliseconds;
    }

    public function getDurations(): array
    {
        return $this->durations;
    }

    public function getCounts(): array
    {
        return $this->counts;
    }

    public function getDbQueryCount(): int
    {
        return $this->dbQueryCount;
    }

    public function getDbCumulativeMs(): float
    {
        return $this->dbCumulativeMs;
    }
}
