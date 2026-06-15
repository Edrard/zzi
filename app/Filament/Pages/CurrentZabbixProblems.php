<?php

namespace App\Filament\Pages;

use App\Services\SettingsService;
use App\Services\Zabbix\ZabbixProblemCache;
use App\Services\Znuny\ZabbixTicketLinkService;
use App\Services\Znuny\ZnunyAgentService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyLookupService;
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

    public bool $isTicketModalOpen = false;

    public ?string $ticketModalEventId = null;

    public ?array $ticketModalProblem = null;

    public ?string $ticketOwnerId = null;

    public ?string $ticketQueue = null;

    public ?string $ticketCustomerUser = null;

    public string $ticketCustomerUserSearch = '';

    public array $ticketQueueOptions = [];

    public array $ticketOwnerOptions = [];

    public array $ticketCustomerUserOptions = [];

    public array $ticketDefaultWarnings = [];

    public array $ticketValidationErrors = [];

    public array $ticketValidationWarnings = [];

    public ?string $ticketValidationStatus = null;

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

    public function openCreateTicketModal(string $eventId): void
    {
        abort_unless(in_array(auth()->user()->role, ['admin', 'operator'], true), 403);

        $linkService = app(ZabbixTicketLinkService::class);
        $existing = $linkService->findByEventId($eventId);
        if ($existing) {
            Notification::make()
                ->title("Ticket already linked: {$existing->znuny_ticket_number}")
                ->info()
                ->send();

            return;
        }

        $problems = $this->getProblemsProperty();
        $problem = collect($problems)->firstWhere('eventid', $eventId);

        if (! $problem) {
            Notification::make()->title('Problem not found')->danger()->send();

            return;
        }

        $this->ticketModalEventId = $eventId;
        $this->ticketModalProblem = $problem;
        $this->ticketValidationErrors = [];
        $this->ticketValidationWarnings = [];
        $this->ticketValidationStatus = null;
        $this->ticketCustomerUserSearch = '';
        $this->ticketCustomerUserOptions = [];

        $hostName = $problem['host_name'] ?? '';

        $lookup = app(ZnunyLookupService::class);
        $agentService = app(ZnunyAgentService::class);
        $client = app(ZnunyClient::class);

        $this->ticketOwnerOptions = collect($agentService->getSelectableAgents())
            ->mapWithKeys(fn (array $agent) => [(string) $agent['id'] => $agent['label']])
            ->toArray();

        $queues = [];
        try {
            $queues = $client->getQueues();
        } catch (\Throwable $e) {
            // skip
        }
        $this->ticketQueueOptions = collect($queues)->pluck('label', 'name')->toArray();

        $this->ticketOwnerId = null;
        $this->ticketQueue = null;
        $this->ticketCustomerUser = null;
        $this->ticketDefaultWarnings = [];

        try {
            $candidates = $lookup->resolveTicketDefaultCandidates($hostName);
            if ($candidates['queue']['found']) {
                $this->ticketQueue = $candidates['queue']['name'];
            }
            if ($candidates['customer_user']['found']) {
                $this->ticketCustomerUser = $candidates['customer_user']['login'];
                $this->ticketCustomerUserOptions[$this->ticketCustomerUser] = $candidates['customer_user']['login'];
            }
            $this->ticketDefaultWarnings = $candidates['warnings'] ?? [];
        } catch (\Throwable $e) {
            $this->ticketDefaultWarnings[] = 'Lookup failed: '.$e->getMessage();
        }

        $this->isTicketModalOpen = true;
        $this->dispatch('open-modal', id: 'create-ticket-modal');
    }

    public function closeCreateTicketModal(): void
    {
        $this->isTicketModalOpen = false;
        $this->dispatch('close-modal', id: 'create-ticket-modal');
    }

    public function searchTicketCustomerUsers(): void
    {
        $search = $this->ticketCustomerUserSearch;
        if (empty(trim($search))) {
            return;
        }

        try {
            $client = app(ZnunyClient::class);
            $results = $client->searchCustomerUsers($search, 20);
            $options = [];
            foreach ($results as $res) {
                $options[$res['login']] = $res['label'];
            }
            $this->ticketCustomerUserOptions = $options;
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public function validateTicketData(): void
    {
        abort_unless(in_array(auth()->user()->role, ['admin', 'operator'], true), 403);

        if (! $this->ticketOwnerId || ! $this->ticketQueue || ! $this->ticketCustomerUser) {
            Notification::make()->title('Missing required fields')->danger()->send();

            return;
        }

        $this->ticketValidationErrors = [];
        $this->ticketValidationWarnings = [];
        $this->ticketValidationStatus = 'validating';

        try {
            $client = app(ZnunyClient::class);
            $response = $client->validateTicketCreate([
                'OwnerID' => (int) $this->ticketOwnerId,
                'Queue' => $this->ticketQueue,
                'CustomerUser' => $this->ticketCustomerUser,
                'State' => 'new',
                'Lock' => 'lock',
            ]);

            if ($response['valid']) {
                $this->ticketValidationStatus = 'success';
                $this->ticketValidationWarnings = $response['warnings'] ?? [];
                Notification::make()->title('Validation successful')->success()->send();
            } else {
                $this->ticketValidationStatus = 'error';
                $this->ticketValidationErrors = $response['errors'] ?? [];
                $this->ticketValidationWarnings = $response['warnings'] ?? [];
            }
        } catch (\Throwable $e) {
            $this->ticketValidationStatus = 'error';
            $this->ticketValidationErrors = [$e->getMessage()];
        }
    }
}
