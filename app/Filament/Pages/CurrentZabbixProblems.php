<?php

namespace App\Filament\Pages;

use App\Services\SettingsService;
use App\Services\Support\DateTimeDisplayService;
use App\Services\Zabbix\ZabbixProblemCache;
use App\Services\Zabbix\ZabbixProblemFormatter;
use App\Services\Zabbix\ZabbixProblemQueryService;
use App\Services\Znuny\ZabbixTicketLinkService;
use App\Services\Znuny\ZnunyAgentService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyManualTicketLifecycleService;
use App\Services\Znuny\ZnunyTicketCreationService;
use App\Services\Znuny\ZnunyTicketModalStateBuilder;
use App\Services\Znuny\ZnunyTicketTextBuilder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

class CurrentZabbixProblems extends Page
{
    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

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

    public ?string $ticketTextTitle = null;

    public ?string $ticketTextArticleSubject = null;

    public ?string $ticketTextArticleBody = null;

    public ?string $generatedTicketTextTitle = null;

    public ?string $generatedTicketTextArticleBody = null;

    public bool $isTicketTextModalOpen = false;

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
        $queryService = app(ZabbixProblemQueryService::class);
        $result = $queryService->query($this->search, $this->sortField, $this->sortDirection);

        $this->totalCachedCount = $result['total_cached_count'];

        return $result['problems'];
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
        return app(ZabbixProblemFormatter::class)->getProblemAgeSeconds($problem);
    }

    public function formatAge(int $seconds): string
    {
        return app(ZabbixProblemFormatter::class)->formatAge($seconds);
    }

    public function formatDateTime(mixed $value): string
    {
        if (empty($value)) {
            return 'N/A';
        }

        return app(DateTimeDisplayService::class)->formatDateTimeWithTimezone($value) ?? 'N/A';
    }

    public function getSeverityColor(int $severity): string
    {
        return app(ZabbixProblemFormatter::class)->getSeverityColor($severity);
    }

    public function getSeverityFallback(int $severity): string
    {
        return app(ZabbixProblemFormatter::class)->getSeverityFallback($severity);
    }

    public function getAgentLabel(int|string $ownerId): ?string
    {
        static $agentMap = null;

        if ($agentMap === null) {
            try {
                // failSilently = true, so no exceptions thrown to break UI
                $agents = app(ZnunyAgentService::class)->getAgents();
                $agentMap = collect($agents)
                    ->mapWithKeys(fn (array $agent) => [(string) $agent['id'] => $agent['label'] ?? $agent['name'] ?? $agent['login'] ?? "Owner ID: {$agent['id']}"])
                    ->toArray();
            } catch (\Throwable $e) {
                $agentMap = [];
            }
        }

        return $agentMap[(string) $ownerId] ?? null;
    }

    public function getTicketOwnerDisplay($linkedTicket): string
    {
        if (! empty($linkedTicket->znuny_owner_name)) {
            return $linkedTicket->znuny_owner_name;
        }

        if (! empty($linkedTicket->znuny_owner_id)) {
            $label = $this->getAgentLabel($linkedTicket->znuny_owner_id);

            return $label ?: "Owner ID: {$linkedTicket->znuny_owner_id}";
        }

        return 'N/A';
    }

    public function openCreateTicketModal(string $eventId): void
    {
        abort_unless(in_array(auth()->user()->role, ['admin', 'operator'], true), 403);

        $linkService = app(ZabbixTicketLinkService::class);
        $existing = $linkService->findByEventId($eventId);
        if ($existing && $existing->manual_lifecycle_status !== ZnunyManualTicketLifecycleService::STATUS_REOPEN_CANDIDATE && $existing->manual_lifecycle_status !== ZnunyManualTicketLifecycleService::STATUS_CLOSED) {
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

        $stateBuilder = app(ZnunyTicketModalStateBuilder::class);
        $state = $stateBuilder->buildState($problem['host_name'] ?? '');

        $this->ticketOwnerOptions = $state['agent_options'];
        $this->ticketQueueOptions = $state['queue_options'];
        $this->ticketOwnerId = $state['default_owner_id'];
        $this->ticketQueue = $state['default_queue'];
        $this->ticketCustomerUser = $state['default_customer_user'];
        $this->ticketCustomerUserOptions = $state['customer_user_options'];
        $this->ticketDefaultWarnings = $state['warnings'];

        $textBuilder = app(ZnunyTicketTextBuilder::class);
        $text = $textBuilder->build($problem);

        $this->generatedTicketTextTitle = $text['title'];
        $this->generatedTicketTextArticleBody = $text['article_body'];

        $this->ticketTextTitle = $text['title'];
        $this->ticketTextArticleSubject = $text['article_subject'];
        $this->ticketTextArticleBody = $text['article_body'];

        $this->isTicketModalOpen = true;
        $this->dispatch('open-modal', id: 'create-ticket-modal');
    }

    public function closeCreateTicketModal(): void
    {
        $this->isTicketModalOpen = false;
        $this->dispatch('close-modal', id: 'create-ticket-modal');
    }

    public function openEditTicketTextModal(): void
    {
        $this->isTicketTextModalOpen = true;
        $this->dispatch('open-modal', id: 'edit-ticket-text-modal');
    }

    public function closeEditTicketTextModal(): void
    {
        $this->isTicketTextModalOpen = false;
        $this->dispatch('close-modal', id: 'edit-ticket-text-modal');
    }

    public function resetTicketText(): void
    {
        $this->ticketTextTitle = $this->generatedTicketTextTitle;
        $this->ticketTextArticleBody = $this->generatedTicketTextArticleBody;
    }

    public function saveTicketText(): void
    {
        // Actually, Livewire models already bind to ticketTextTitle and ticketTextArticleBody,
        // so we just close the modal.
        $this->closeEditTicketTextModal();
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

    private function getTicketModalHostName(): string
    {
        if (! empty($this->ticketModalProblem['host_name'])) {
            return $this->ticketModalProblem['host_name'];
        }

        if (! empty($this->ticketModalProblem['hosts'][0]['host'])) {
            return $this->ticketModalProblem['hosts'][0]['host'];
        }

        if (! empty($this->ticketModalProblem['hosts'][0]['name'])) {
            return $this->ticketModalProblem['hosts'][0]['name'];
        }

        return '';
    }

    public bool $isCreatingTicket = false;

    public function createZnunyTicket(): void
    {
        abort_unless(in_array(auth()->user()->role, ['admin', 'operator'], true), 403);

        if ($this->isCreatingTicket) {
            return;
        }

        if (! $this->ticketModalProblem || ! $this->ticketModalEventId) {
            Notification::make()->title('Missing event context')->danger()->send();

            return;
        }

        $this->isCreatingTicket = true;

        try {
            $this->ticketValidationErrors = [];
            $this->ticketValidationWarnings = [];
            $this->ticketValidationStatus = 'validating';

            $hostId = (string) ($this->ticketModalProblem['hosts'][0]['hostid'] ?? '');
            $triggerId = (string) ($this->ticketModalProblem['objectid'] ?? $this->ticketModalProblem['triggerid'] ?? '');
            $startedAt = null;
            if (! empty($this->ticketModalProblem['clock'])) {
                $startedAt = Carbon::createFromTimestamp($this->ticketModalProblem['clock'])->toDateTimeString();
            } elseif (! empty($this->ticketModalProblem['started_at'])) {
                try {
                    $startedAt = Carbon::parse($this->ticketModalProblem['started_at'])->toDateTimeString();
                } catch (\Throwable $e) {
                }
            }

            $service = app(ZnunyTicketCreationService::class);
            $result = $service->createTicketForProblem(
                (string) $this->ticketModalEventId,
                $this->getTicketModalHostName(),
                (string) ($this->ticketModalProblem['name'] ?? ''),
                $this->ticketOwnerId ?? '',
                (string) $this->ticketQueue,
                (string) $this->ticketCustomerUser,
                (string) $this->ticketTextTitle,
                (string) $this->ticketTextArticleSubject,
                (string) $this->ticketTextArticleBody,
                $hostId === '' ? null : $hostId,
                $triggerId === '' ? null : $triggerId,
                $startedAt
            );

            if ($result['success']) {
                $this->ticketValidationStatus = 'success';
                $this->ticketValidationWarnings = $result['warnings'];

                Notification::make()
                    ->title("Znuny ticket created: {$result['ticket_number']}")
                    ->success()
                    ->send();

                $this->closeCreateTicketModal();
                $this->isTicketTextModalOpen = false;
            } else {
                $this->ticketValidationStatus = 'error';
                $this->ticketValidationErrors = $result['errors'];
                $this->ticketValidationWarnings = $result['warnings'] ?? [];

                if (! empty($result['orphaned'])) {
                    Notification::make()
                        ->title("Znuny ticket was created but local link failed. Ticket: {$result['ticket_number']}. Check logs.")
                        ->danger()
                        ->persistent()
                        ->send();
                } else {
                    $mainError = $result['errors'][0] ?? 'Failed to create ticket.';
                    Notification::make()
                        ->title($mainError)
                        ->danger()
                        ->send();
                }
            }
        } finally {
            $this->isCreatingTicket = false;
        }
    }
}
