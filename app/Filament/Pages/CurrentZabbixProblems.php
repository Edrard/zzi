<?php

namespace App\Filament\Pages;

use App\Services\SettingsService;
use App\Services\Zabbix\ZabbixProblemCache;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;

class CurrentZabbixProblems extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server';

    protected string $view = 'filament.pages.current-zabbix-problems';

    protected static string|\UnitEnum|null $navigationGroup = 'Zabbix';

    protected static ?string $navigationLabel = 'Current Problems';

    protected static ?string $title = 'Current Zabbix Problems';

    public string $search = '';

    public string $sortField = 'age';

    public string $sortDirection = 'asc';

    public int $totalCachedCount = 0;

    public static function canAccess(): bool
    {
        return in_array(auth()->user()->role, ['admin', 'operator', 'viewer'], true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh from Zabbix')
                ->icon('heroicon-o-arrow-path')
                ->action('refreshFromZabbix')
                ->visible(fn () => in_array(auth()->user()->role, ['admin', 'operator'], true)),
        ];
    }

    public function refreshFromZabbix(): void
    {
        abort_unless(
            in_array(auth()->user()->role, ['admin', 'operator'], true),
            403
        );

        try {
            $exitCode = Artisan::call('app:poll-zabbix-problems', ['--force' => true]);

            if ($exitCode === 0) {
                Notification::make()
                    ->title('Zabbix problems refreshed successfully')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Failed to refresh Zabbix problems')
                    ->danger()
                    ->send();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title('An error occurred while refreshing Zabbix problems')
                ->danger()
                ->send();
        }
    }

    public function sortBy(string $field): void
    {
        $allowed = ['severity', 'host', 'problem', 'age'];
        if (! in_array($field, $allowed, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = match ($field) {
                'severity' => 'desc',
                'age' => 'asc',
                'host', 'problem' => 'asc',
                default => 'asc',
            };
        }
    }

    public function getProblemsProperty(): array
    {
        $cache = app(ZabbixProblemCache::class);
        $problems = $cache->all();
        $this->totalCachedCount = count($problems);

        if (! empty($this->search)) {
            $term = mb_strtolower($this->search);
            $problems = array_filter($problems, function ($problem) use ($term) {
                $hostMatch = mb_stripos($problem['host_name'] ?? '', $term) !== false;
                $nameMatch = mb_stripos($problem['name'] ?? '', $term) !== false;

                return $hostMatch || $nameMatch;
            });
        }

        $direction = $this->sortDirection === 'asc' ? 1 : -1;

        usort($problems, function ($a, $b) use ($direction) {
            $sevA = (int) ($a['severity'] ?? 0);
            $sevB = (int) ($b['severity'] ?? 0);

            $ageA = $this->getProblemAgeSeconds($a);
            $ageB = $this->getProblemAgeSeconds($b);

            $hostA = mb_strtolower($a['host_name'] ?? '');
            $hostB = mb_strtolower($b['host_name'] ?? '');

            $probA = mb_strtolower($a['name'] ?? '');
            $probB = mb_strtolower($b['name'] ?? '');

            $idA = $a['eventid'] ?? '';
            $idB = $b['eventid'] ?? '';

            if ($this->sortField === 'severity') {
                if ($sevA !== $sevB) {
                    return ($sevA <=> $sevB) * $direction;
                }

                return $ageB <=> $ageA; // fallback age desc
            }

            if ($this->sortField === 'age') {
                if ($ageA !== $ageB) {
                    return ($ageA <=> $ageB) * $direction;
                }

                return $sevB <=> $sevA; // fallback sev desc
            }

            if ($this->sortField === 'host') {
                if ($hostA !== $hostB) {
                    return strcmp($hostA, $hostB) * $direction;
                }
                if ($sevA !== $sevB) {
                    return $sevB <=> $sevA;
                }

                return strcmp($idA, $idB);
            }

            if ($this->sortField === 'problem') {
                if ($probA !== $probB) {
                    return strcmp($probA, $probB) * $direction;
                }
                if ($sevA !== $sevB) {
                    return $sevB <=> $sevA;
                }

                return strcmp($idA, $idB);
            }

            return 0;
        });

        return $problems;
    }

    public function getLastPollProperty(): ?array
    {
        $cache = app(ZabbixProblemCache::class);

        return $cache->lastPoll();
    }

    public function getRefreshIntervalString(): string
    {
        $minutes = SettingsService::int('zabbix_poll_interval_minutes', 1) ?? 1;
        $seconds = (int) round(($minutes * 60) / 2);

        $finalSeconds = max($seconds, 10);

        return "{$finalSeconds}s";
    }

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
