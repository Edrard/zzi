<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ZabbixTickets\Actions\ZabbixTicketDetailsAction;
use App\Filament\Support\ZnunyTicketManagementActions;
use App\Models\ZabbixTicket;
use App\Services\OwnerSuggestion\OwnerSuggestionSelector;
use App\Services\Support\DateTimeDisplayService;
use App\Services\Zabbix\ZabbixProblemCache;
use App\Services\Zabbix\ZabbixProblemFormatter;
use App\Services\Zabbix\ZabbixProblemQueryService;
use App\Services\Zabbix\ZabbixTicketStatusPresenter;
use App\Services\Znuny\ZabbixTicketLinkService;
use App\Services\Znuny\ZnunyAgentService;
use App\Services\Znuny\ZnunyAssignmentDependencyService;
use App\Services\Znuny\ZnunyCachedLookupService;
use App\Services\Znuny\ZnunyManualTicketLifecycleService;
use App\Services\Znuny\ZnunyTicketCreationService;
use App\Services\Znuny\ZnunyTicketModalStateBuilder;
use App\Services\Znuny\ZnunyTicketTextBuilder;
use App\Support\Polling\UiPollInterval;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class CurrentZabbixProblems extends Page
{
    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server';

    protected string $view = 'filament.pages.current-zabbix-problems';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.zabbix');
    }

    public static function getNavigationLabel(): string
    {
        return __('current_zabbix_problems.navigation');
    }

    public function getTitle(): string|Htmlable
    {
        return __('current_zabbix_problems.title');
    }

    public ?string $search = '';

    public string $problemPreset = 'all';

    public string $sortField = 'age';

    public string $sortDirection = 'asc';

    public int $totalCachedCount = 0;

    public bool $isTicketModalOpen = false;

    public ?string $ticketModalEventId = null;

    public ?array $ticketModalProblem = null;

    public string $ticketDefaultPriority = '3 normal';

    public string $ticketDefaultState = 'new';

    public string $ticketDefaultLock = 'lock';

    public ?string $ticketOwnerId = null;

    public ?string $ticketQueue = null;

    public ?string $ticketCustomerUser = null;

    public string $ticketCustomerUserSearch = '';

    public array $ticketQueueOptions = [];

    public array $ticketOwnerOptions = [];

    public array $ticketCustomerUserOptions = [];

    public array $ticketDefaultNotes = [];

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

    public ?string $suggestedOwnerId = null;

    public ?string $suggestedOwnerLogin = null;

    public bool $ownerSuggestionApplied = false;

    public bool $ownerManuallyChanged = false;

    public static function canAccess(): bool
    {
        return in_array(auth()->user()->role, ['admin', 'operator', 'viewer'], true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(__('current_zabbix_problems.refresh_from_zabbix'))
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
            $exitCode = Artisan::call('app:poll-zabbix-problems', ['--force' => true, '--manual' => true]);

            if ($exitCode === 0) {
                $evalExitCode = Artisan::call('znuny:evaluate-manual-ticket-lifecycle');

                if ($evalExitCode === 0) {
                    Notification::make()
                        ->title(__('current_zabbix_problems.notifications.refresh_success'))
                        ->body(__('current_zabbix_problems.notifications.refresh_success_body'))
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title(__('current_zabbix_problems.notifications.refresh_success'))
                        ->body(__('current_zabbix_problems.notifications.refresh_partial_body'))
                        ->danger()
                        ->send();
                }
            } else {
                Notification::make()
                    ->title(__('current_zabbix_problems.notifications.refresh_failed'))
                    ->danger()
                    ->send();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('current_zabbix_problems.notifications.refresh_error'))
                ->danger()
                ->send();
        }
    }

    public function reopenTicketAction(): Action
    {
        return ZnunyTicketManagementActions::reopenTicketAction('reopenTicket');
    }

    public function viewTicketAction(): Action
    {
        return ZabbixTicketDetailsAction::make('viewTicket')
            ->record(function (array $arguments) {
                return ZabbixTicket::find($arguments['zabbix_ticket_id'] ?? null);
            });
    }

    public function getAction(string|array $actions, bool $isMounting = true): ?Action
    {
        if (empty($actions) || (is_string($actions) && trim($actions) === '')) {
            return null;
        }

        return parent::getAction($actions, $isMounting);
    }

    public function setProblemPreset(string $preset): void
    {
        $allowed = ['all', 'high', 'warning', 'average', 'information', 'tickets', 'reopen', 'flapping'];
        if (in_array($preset, $allowed, true)) {
            $this->problemPreset = $preset;
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
        $problems = $result['problems'];

        if ($this->problemPreset !== 'all') {
            $filtered = [];

            if (in_array($this->problemPreset, ['tickets', 'reopen', 'flapping'], true)) {
                $resolvedTickets = $this->resolveLinkedTickets($problems);
            }

            foreach ($problems as $problem) {
                $severity = (int) ($problem['severity'] ?? 0);
                $eventId = (string) ($problem['eventid'] ?? $problem['objectid'] ?? $problem['triggerid'] ?? '');

                $keep = match ($this->problemPreset) {
                    'high' => $severity >= 4,
                    'warning' => $severity === 2,
                    'average' => $severity === 3,
                    'information' => $severity === 1,
                    'tickets' => isset($resolvedTickets[$eventId]),
                    'reopen' => isset($resolvedTickets[$eventId]) && (
                        in_array($resolvedTickets[$eventId]->manual_lifecycle_status, ['reopen_candidate', 'reopened'], true) ||
                        ! is_null($resolvedTickets[$eventId]->manual_reopened_at)
                    ),
                    'flapping' => isset($resolvedTickets[$eventId]) && $resolvedTickets[$eventId]->manual_lifecycle_status === 'flapping',
                    default => true,
                };

                if ($keep) {
                    $filtered[] = $problem;
                }
            }
            $problems = $filtered;
        }

        return $problems;
    }

    public function resolveLinkedTickets(array $problems): array
    {
        $allEventIds = [];
        foreach ($problems as $problem) {
            if (! empty($problem['eventid'])) {
                $allEventIds[] = $problem['eventid'];
            }
            if (! empty($problem['related_eventids']) && is_array($problem['related_eventids'])) {
                foreach ($problem['related_eventids'] as $rId) {
                    $allEventIds[] = $rId;
                }
            }
        }
        $allEventIds = array_unique(array_filter($allEventIds));

        $ticketsByEventId = ZabbixTicket::whereIn('zabbix_event_id', $allEventIds)->get()->keyBy('zabbix_event_id');

        $hostIds = collect($problems)->map(fn ($p) => $p['hosts'][0]['hostid'] ?? ($p['hostid'] ?? null))->filter()->unique()->toArray();
        $triggerIds = collect($problems)->map(fn ($p) => $p['objectid'] ?? ($p['triggerid'] ?? null))->filter()->unique()->toArray();

        $ticketsByHostTrigger = collect();
        if (! empty($hostIds) && ! empty($triggerIds)) {
            $ticketsByHostTrigger = ZabbixTicket::where(function ($query) {
                $query->whereNotNull('znuny_ticket_id')
                    ->orWhereNotNull('znuny_ticket_number');
            })
                ->whereIn('zabbix_host_id', $hostIds)
                ->whereIn('zabbix_trigger_id', $triggerIds)
                ->get()
                ->groupBy(function ($t) {
                    return $t->zabbix_host_id.'_'.$t->zabbix_trigger_id;
                });
        }

        $resolved = [];
        foreach ($problems as $problem) {
            $primaryEventId = (string) ($problem['eventid'] ?? $problem['objectid'] ?? $problem['triggerid'] ?? '');
            if (! $primaryEventId) {
                continue;
            }

            $hostId = $problem['hosts'][0]['hostid'] ?? ($problem['hostid'] ?? null);
            $triggerId = $problem['objectid'] ?? ($problem['triggerid'] ?? null);
            $hostTriggerKey = $hostId && $triggerId ? $hostId.'_'.$triggerId : null;

            $candidateEventIds = ! empty($problem['related_eventids']) && is_array($problem['related_eventids'])
                ? $problem['related_eventids']
                : [$primaryEventId];

            $linkedTicket = null;
            $ignoredStatuses = [
                ZnunyManualTicketLifecycleService::STATUS_CLOSED,
                ZnunyManualTicketLifecycleService::STATUS_NOT_APPLICABLE,
                ZnunyManualTicketLifecycleService::STATUS_CACHE_STALE,
                ZnunyManualTicketLifecycleService::STATUS_IDENTITY_MISSING,
            ];

            // 1. Try finding by event ID (checking all related IDs)
            foreach ($candidateEventIds as $cId) {
                $candidate = $ticketsByEventId[$cId] ?? null;
                if ($candidate && ! in_array($candidate->manual_lifecycle_status, $ignoredStatuses)) {
                    $linkedTicket = $candidate;
                    break;
                }
            }

            // 2. Try finding by host+trigger fallback if no valid event ID ticket was found
            if (! $linkedTicket && $hostTriggerKey && isset($ticketsByHostTrigger[$hostTriggerKey])) {
                $candidates = $ticketsByHostTrigger[$hostTriggerKey]->reject(function ($t) use ($ignoredStatuses) {
                    return in_array($t->manual_lifecycle_status, $ignoredStatuses);
                });

                if ($candidates->isNotEmpty()) {
                    $linkedTicket = $candidates->sortBy(function ($t) {
                        $statusScore = match ($t->manual_lifecycle_status) {
                            'flapping' => 1,
                            'reopen_candidate' => 2,
                            'reopened' => 3,
                            'active' => 4,
                            'resolved_waiting' => 5,
                            'close_candidate' => 6,
                            default => 8,
                        };
                        $stateScore = strtolower($t->znuny_ticket_state_type ?? '') === 'closed' ? 1 : 0;

                        return sprintf('%d-%d-%012d', $statusScore, $stateScore, 999999999999 - $t->updated_at->timestamp);
                    })->first();
                }
            }

            if ($linkedTicket) {
                $resolved[$primaryEventId] = $linkedTicket;
            }
        }

        return $resolved;
    }

    public function getProblemTicketIndicator(?ZabbixTicket $ticket): ?array
    {
        return ZabbixTicketStatusPresenter::problemIndicator($ticket);
    }

    public function getLastPollProperty(): ?array
    {
        $cache = app(ZabbixProblemCache::class);

        return $cache->lastPoll();
    }

    public function getRefreshIntervalString(): string
    {
        return UiPollInterval::getLivewireString();
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

        return app(DateTimeDisplayService::class)->formatDateTime($value) ?? 'N/A';
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
                ->title(__('current_zabbix_problems.tooltips.ticket_already_linked', ['ticket' => $existing->znuny_ticket_number]))
                ->info()
                ->send();

            return;
        }

        $problems = $this->getProblemsProperty();
        $problem = collect($problems)->firstWhere('eventid', $eventId);

        if (! $problem) {
            Notification::make()->title(__('current_zabbix_problems.notifications.problem_not_found'))->danger()->send();

            return;
        }

        $this->ticketModalEventId = $eventId;
        $this->ticketModalProblem = $problem;
        $this->ticketDefaultNotes = [];
        $this->ticketValidationErrors = [];
        $this->ticketValidationWarnings = [];
        $this->ticketValidationStatus = null;
        $this->ticketCustomerUserSearch = '';
        $this->ticketCustomerUserOptions = [];

        $this->suggestedOwnerId = null;
        $this->suggestedOwnerLogin = null;
        $this->ownerSuggestionApplied = false;
        $this->ownerManuallyChanged = false;

        $stateBuilder = app(ZnunyTicketModalStateBuilder::class);
        $state = $stateBuilder->buildState($problem['host_name'] ?? '');

        $this->ticketOwnerId = ! empty($state['default_owner_id']) ? (string) $state['default_owner_id'] : null;
        $this->ticketQueue = $state['default_queue'];
        $this->ticketCustomerUser = $state['default_customer_user'];
        $this->ticketCustomerUserOptions = $state['customer_user_options'];
        $this->ticketDefaultNotes = $state['notes'] ?? [];
        $this->ticketDefaultWarnings = $state['warnings'];

        $this->ticketDefaultPriority = $state['priority'] ?? '3 normal';
        $this->ticketDefaultState = $state['state'] ?? 'new';
        $this->ticketDefaultLock = $state['lock'] ?? 'lock';

        $dependencyService = app(ZnunyAssignmentDependencyService::class);

        $this->ticketOwnerOptions = $dependencyService->getOwnerOptionsForQueue($this->ticketQueue);
        $this->ticketQueueOptions = $dependencyService->getQueueOptionsForOwnerId($this->ticketOwnerId);

        if ($this->ticketQueue && $this->ticketOwnerId) {
            if (! $dependencyService->isOwnerValidForQueue($this->ticketOwnerId, $this->ticketQueue)) {
                $this->ticketOwnerId = null;
                $this->ticketDefaultWarnings[] = 'Default owner cleared because it is not assignable to the default queue.';
                $this->ticketOwnerOptions = $dependencyService->getOwnerOptionsForQueue($this->ticketQueue);
            }
        } elseif ($this->ticketQueue) {
            $this->ticketOwnerOptions = $dependencyService->getOwnerOptionsForQueue($this->ticketQueue);
        } elseif ($this->ticketOwnerId) {
            $this->ticketQueueOptions = $dependencyService->getQueueOptionsForOwnerId($this->ticketOwnerId);
        }

        $textBuilder = app(ZnunyTicketTextBuilder::class);
        $text = $textBuilder->build($problem);

        $this->generatedTicketTextTitle = $text['title'];
        $this->generatedTicketTextArticleBody = $text['article_body'];

        $this->ticketTextTitle = $text['title'];
        $this->ticketTextArticleSubject = $text['article_subject'];
        $this->ticketTextArticleBody = $text['article_body'];

        $this->applyOwnerSuggestion();

        $this->isTicketModalOpen = true;
        $this->dispatch('open-modal', id: 'create-ticket-modal');
    }

    public function updatedTicketQueue(?string $queueName): void
    {
        $dependencyService = app(ZnunyAssignmentDependencyService::class);
        $this->ticketOwnerOptions = $dependencyService->getOwnerOptionsForQueue($queueName);

        if ($this->ticketOwnerId && ! array_key_exists((string) $this->ticketOwnerId, $this->ticketOwnerOptions)) {
            $this->ticketOwnerId = null;
            $this->ownerManuallyChanged = false;
            Notification::make()
                ->title(__('current_zabbix_problems.notifications.owner_cleared'))
                ->body(__('current_zabbix_problems.notifications.owner_cleared_body'))
                ->warning()
                ->send();
        }

        $this->applyOwnerSuggestion();
    }

    public function updatedTicketOwnerId(?string $ownerId): void
    {
        $this->ownerManuallyChanged = true;

        $dependencyService = app(ZnunyAssignmentDependencyService::class);
        $this->ticketQueueOptions = $dependencyService->getQueueOptionsForOwnerId($ownerId);

        if ($this->ticketQueue && ! array_key_exists($this->ticketQueue, $this->ticketQueueOptions)) {
            $this->ticketQueue = null;
            Notification::make()
                ->title(__('current_zabbix_problems.notifications.queue_cleared'))
                ->body(__('current_zabbix_problems.notifications.queue_cleared_body'))
                ->warning()
                ->send();
        }
    }

    protected function applyOwnerSuggestion(): void
    {
        try {
            $selector = app(OwnerSuggestionSelector::class);
            $dependencyService = app(ZnunyAssignmentDependencyService::class);

            $problemName = $this->ticketModalProblem['name'] ?? '';
            $queueName = $this->ticketQueue;
            $allowedOwnerIds = array_keys($this->ticketOwnerOptions);

            $agents = $dependencyService->getAssignableAgentsForQueue($queueName);
            $allowedOwnerLogins = [];
            $loginToIdMap = [];
            foreach ($agents as $agent) {
                if (! empty($agent['login']) && ! empty($agent['id'])) {
                    $login = (string) $agent['login'];
                    $allowedOwnerLogins[] = $login;
                    $loginToIdMap[$login] = (string) $agent['id'];
                }
            }

            $rankedCandidates = $selector->rank($problemName, $queueName, $allowedOwnerIds, $allowedOwnerLogins);

            if (! empty($rankedCandidates)) {
                $resolvedRankedOwnerIds = [];
                $resolvedLogins = [];

                foreach ($rankedCandidates as $suggestion) {
                    $resolvedSuggestedOwnerId = null;

                    if (! empty($suggestion['owner_id']) && array_key_exists((string) $suggestion['owner_id'], $this->ticketOwnerOptions)) {
                        $resolvedSuggestedOwnerId = (string) $suggestion['owner_id'];
                    } elseif (! empty($suggestion['owner_login']) && isset($loginToIdMap[(string) $suggestion['owner_login']])) {
                        $resolvedSuggestedOwnerId = $loginToIdMap[(string) $suggestion['owner_login']];
                    }

                    if ($resolvedSuggestedOwnerId && ! in_array($resolvedSuggestedOwnerId, $resolvedRankedOwnerIds, true)) {
                        $resolvedRankedOwnerIds[] = $resolvedSuggestedOwnerId;
                        $resolvedLogins[] = $suggestion['owner_login'] ?? null;
                    }
                }

                if (! empty($resolvedRankedOwnerIds)) {
                    $this->suggestedOwnerId = $resolvedRankedOwnerIds[0];
                    $this->suggestedOwnerLogin = $resolvedLogins[0];

                    if (! $this->ownerManuallyChanged) {
                        $this->ticketOwnerId = $this->suggestedOwnerId;
                        $this->ownerSuggestionApplied = true;

                        $this->ticketQueueOptions = $dependencyService->getQueueOptionsForOwnerId($this->ticketOwnerId);
                    }

                    $newOptions = [];
                    foreach ($resolvedRankedOwnerIds as $id) {
                        $newOptions[$id] = $this->ticketOwnerOptions[$id];
                    }
                    foreach ($this->ticketOwnerOptions as $id => $label) {
                        if (! in_array($id, $resolvedRankedOwnerIds, true)) {
                            $newOptions[$id] = $label;
                        }
                    }
                    $this->ticketOwnerOptions = $newOptions;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to apply owner suggestion: '.$e->getMessage());
        }
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
            $lookupService = app(ZnunyCachedLookupService::class);
            $this->ticketCustomerUserOptions = $lookupService->searchCustomerUserOptions($search, 20);
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
            Notification::make()->title(__('current_zabbix_problems.notifications.missing_event_context'))->danger()->send();

            return;
        }

        $this->isCreatingTicket = true;

        try {
            $this->ticketValidationErrors = [];
            $this->ticketValidationWarnings = [];

            if (empty($this->ticketOwnerId) || empty($this->ticketQueue) || empty($this->ticketCustomerUser)) {
                $this->ticketValidationErrors[] = __('current_zabbix_problems.validation.required_fields');
                $this->ticketValidationStatus = 'error';

                return;
            }

            $dependencyService = app(ZnunyAssignmentDependencyService::class);
            if (! $dependencyService->isOwnerValidForQueue($this->ticketOwnerId, $this->ticketQueue)) {
                $this->ticketValidationErrors[] = __('current_zabbix_problems.validation.owner_not_assignable');
                $this->ticketValidationStatus = 'error';

                return;
            }

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
                    ->title(__('current_zabbix_problems.notifications.ticket_created', ['ticket' => $result['ticket_number']]))
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
                        ->title(__('current_zabbix_problems.notifications.ticket_created_orphaned', ['ticket' => $result['ticket_number']]))
                        ->danger()
                        ->persistent()
                        ->send();
                } else {
                    $mainError = $result['errors'][0] ?? __('current_zabbix_problems.notifications.failed_to_create');
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
