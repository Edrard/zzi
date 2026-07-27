<?php

namespace App\Filament\Resources\ScheduledZnunyTaskRuns\Pages;

use App\Enums\ScheduledZnunyTicketMarkerLookupStatus;
use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Filament\Resources\ScheduledZnunyTaskRuns\ScheduledZnunyTaskRunResource;
use App\Services\Znuny\ScheduledZnunyTicketCreationAttemptManualLinkService;
use App\Services\Znuny\ScheduledZnunyTicketCreationAttemptManualRetryService;
use App\Services\Znuny\ScheduledZnunyTicketCreationAttemptManualReviewService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReviewScheduledZnunyTaskRunAttempt extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ScheduledZnunyTaskRunResource::class;

    protected string $view = 'filament.resources.scheduled-znuny-task-runs.pages.review-scheduled-znuny-task-run-attempt';

    public array $reviewContext = [];

    public ?string $lookupStatus = null;

    public array $lookupMatches = [];

    public ?string $lastRecheckedAt = null;

    public function getTitle(): string|Htmlable
    {
        return __('scheduled_znuny_task_runs.review.title');
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $reviewService = app(ScheduledZnunyTicketCreationAttemptManualReviewService::class);
        $this->reloadPersistentContext($reviewService);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('manual_link')
                ->label(__('scheduled_znuny_task_runs.review.actions.manual_link.label'))
                ->icon('heroicon-o-link')
                ->color('primary')
                ->visible(fn () => ($this->reviewContext['eligible'] ?? false) === true
                    && ($this->reviewContext['attempt_status'] ?? null) === ZnunyTicketCreationAttemptStatus::Uncertain->value
                    && in_array($this->lookupStatus, [ScheduledZnunyTicketMarkerLookupStatus::Found->value, ScheduledZnunyTicketMarkerLookupStatus::Multiple->value], true)
                    && count($this->lookupMatches) > 0
                )
                ->requiresConfirmation()
                ->modalHeading(__('scheduled_znuny_task_runs.review.actions.manual_link.modal_heading'))
                ->modalDescription(function () {
                    if ($this->lookupStatus === ScheduledZnunyTicketMarkerLookupStatus::Found->value && count($this->lookupMatches) === 1) {
                        return __('scheduled_znuny_task_runs.review.actions.manual_link.modal_description_found', [
                            'ticket_number' => $this->lookupMatches[0]['ticket_number'],
                            'ticket_id' => $this->lookupMatches[0]['ticket_id'],
                        ]);
                    }

                    return __('scheduled_znuny_task_runs.review.actions.manual_link.modal_description_multiple');
                })
                ->modalSubmitActionLabel(__('scheduled_znuny_task_runs.review.actions.manual_link.submit'))
                ->form(function () {
                    if ($this->lookupStatus === ScheduledZnunyTicketMarkerLookupStatus::Found->value && count($this->lookupMatches) === 1) {
                        return [];
                    }

                    $options = [];
                    foreach ($this->lookupMatches as $index => $match) {
                        $options[$index] = $match['ticket_number'].' (ID: '.$match['ticket_id'].')';
                    }

                    return [
                        Select::make('selected_ticket')
                            ->label(__('scheduled_znuny_task_runs.review.actions.manual_link.select_ticket_label'))
                            ->options($options)
                            ->required(),
                    ];
                })
                ->action(function (array $data, ScheduledZnunyTicketCreationAttemptManualLinkService $linkService, ScheduledZnunyTicketCreationAttemptManualReviewService $reviewService) {
                    $attemptId = $this->reviewContext['attempt_id'] ?? null;
                    if (! $attemptId) {
                        $this->safelyReloadPersistentContext($reviewService);
                        Notification::make()
                            ->title(__('scheduled_znuny_task_runs.review.notifications.changed.title'))
                            ->body(__('scheduled_znuny_task_runs.review.notifications.changed.body'))
                            ->warning()
                            ->send();

                        return;
                    }

                    if ($this->lookupStatus === ScheduledZnunyTicketMarkerLookupStatus::Found->value && count($this->lookupMatches) === 1) {
                        $ticketId = $this->lookupMatches[0]['ticket_id'];
                        $ticketNumber = $this->lookupMatches[0]['ticket_number'];
                    } else {
                        $matchIndex = $data['selected_ticket'] ?? null;
                        $match = $matchIndex !== null && $matchIndex !== '' ? ($this->lookupMatches[$matchIndex] ?? null) : null;
                        if (! $match) {
                            $this->safelyReloadPersistentContext($reviewService);
                            Notification::make()
                                ->title(__('scheduled_znuny_task_runs.review.notifications.changed.title'))
                                ->body(__('scheduled_znuny_task_runs.review.notifications.changed.body'))
                                ->warning()
                                ->send();

                            return;
                        }
                        $ticketId = $match['ticket_id'];
                        $ticketNumber = $match['ticket_number'];
                    }

                    try {
                        $result = $linkService->link($attemptId, $ticketId, $ticketNumber);
                    } catch (\Throwable $e) {
                        $this->safelyReloadPersistentContext($reviewService);
                        Notification::make()
                            ->title(__('scheduled_znuny_task_runs.review.notifications.unexpected_error.title'))
                            ->body(__('scheduled_znuny_task_runs.review.notifications.unexpected_error.body'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->safelyReloadPersistentContext($reviewService);

                    if ($result['linked'] && $result['transitioned']) {
                        Notification::make()
                            ->title(__('scheduled_znuny_task_runs.review.notifications.manual_link_success.title'))
                            ->success()
                            ->send();
                    } elseif ($result['linked'] && ! $result['transitioned']) {
                        Notification::make()
                            ->title(__('scheduled_znuny_task_runs.review.notifications.manual_link_idempotent.title'))
                            ->body(__('scheduled_znuny_task_runs.review.notifications.manual_link_idempotent.body'))
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title(__('scheduled_znuny_task_runs.review.notifications.manual_link_conflict.title'))
                            ->body(__('scheduled_znuny_task_runs.review.notifications.manual_link_conflict.body'))
                            ->warning()
                            ->send();
                    }
                }),

            Action::make('manual_retry')
                ->label(__('scheduled_znuny_task_runs.review.actions.manual_retry.label'))
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('danger')
                ->visible(fn () => ($this->reviewContext['eligible'] ?? false) === true
                    && ($this->reviewContext['attempt_status'] ?? null) === ZnunyTicketCreationAttemptStatus::Uncertain->value
                    && $this->lookupStatus === ScheduledZnunyTicketMarkerLookupStatus::NotFound->value
                )
                ->requiresConfirmation()
                ->modalHeading(__('scheduled_znuny_task_runs.review.actions.manual_retry.modal_heading'))
                ->modalDescription(__('scheduled_znuny_task_runs.review.actions.manual_retry.modal_description'))
                ->modalSubmitActionLabel(__('scheduled_znuny_task_runs.review.actions.manual_retry.submit'))
                ->action(function (ScheduledZnunyTicketCreationAttemptManualRetryService $retryService, ScheduledZnunyTicketCreationAttemptManualReviewService $reviewService) {
                    $attemptId = $this->reviewContext['attempt_id'] ?? null;
                    if (! $attemptId) {
                        $this->safelyReloadPersistentContext($reviewService);
                        Notification::make()
                            ->title(__('scheduled_znuny_task_runs.review.notifications.changed.title'))
                            ->body(__('scheduled_znuny_task_runs.review.notifications.changed.body'))
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        $result = $retryService->retry($attemptId);
                    } catch (\Throwable $e) {
                        $this->safelyReloadPersistentContext($reviewService);
                        Notification::make()
                            ->title(__('scheduled_znuny_task_runs.review.notifications.unexpected_error.title'))
                            ->body(__('scheduled_znuny_task_runs.review.notifications.unexpected_error.body'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->safelyReloadPersistentContext($reviewService);

                    if ($result['created']) {
                        Notification::make()
                            ->title(__('scheduled_znuny_task_runs.review.notifications.manual_retry_success.title'))
                            ->body(__('scheduled_znuny_task_runs.review.notifications.manual_retry_success.body', ['run_id' => $result['retry_run_id']]))
                            ->success()
                            ->send();
                    } elseif ($result['existing']) {
                        Notification::make()
                            ->title(__('scheduled_znuny_task_runs.review.notifications.manual_retry_idempotent.title'))
                            ->body(__('scheduled_znuny_task_runs.review.notifications.manual_retry_idempotent.body', ['run_id' => $result['retry_run_id']]))
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title(__('scheduled_znuny_task_runs.review.notifications.manual_retry_conflict.title'))
                            ->body(__('scheduled_znuny_task_runs.review.notifications.manual_retry_conflict.body'))
                            ->warning()
                            ->send();
                    }
                }),

            Action::make('recheck')
                ->label(__('scheduled_znuny_task_runs.review.actions.recheck'))
                ->icon('heroicon-o-arrow-path')
                ->action('recheck')
                ->visible(fn () => ($this->reviewContext['eligible'] ?? false) === true
                    && ($this->reviewContext['attempt_status'] ?? null) === ZnunyTicketCreationAttemptStatus::Uncertain->value
                    && ! empty($this->reviewContext['attempt_id'])
                ),
        ];
    }

    public function recheck(ScheduledZnunyTicketCreationAttemptManualReviewService $reviewService): void
    {
        $attemptId = $this->reviewContext['attempt_id'] ?? null;

        if (! $attemptId) {
            $this->safelyReloadPersistentContext($reviewService);
            Notification::make()
                ->title(__('scheduled_znuny_task_runs.review.notifications.changed.title'))
                ->body(__('scheduled_znuny_task_runs.review.notifications.changed.body'))
                ->warning()
                ->send();

            return;
        }
        $rawContext = $reviewService->forceRecheck($attemptId);
        $normalized = $this->normalizeServiceContext($rawContext);

        $this->record->refresh();
        $this->record->load(['task', 'latestZnunyTicketCreationAttempt']);
        $currentAttempt = $this->record->latestZnunyTicketCreationAttempt;

        if ($this->hasConcurrentStateChange($normalized, $attemptId, $currentAttempt)) {
            $this->safelyReloadPersistentContext($reviewService);

            Notification::make()
                ->title(__('scheduled_znuny_task_runs.review.notifications.changed.title'))
                ->body(__('scheduled_znuny_task_runs.review.notifications.changed.body'))
                ->warning()
                ->send();

            return;
        }

        $this->applyReviewContext($normalized, true);
        $this->notifyRecheckResult();
    }

    private function hasConcurrentStateChange(array $normalized, int|string $attemptId, $currentAttempt): bool
    {
        if (! $currentAttempt) {
            return true;
        }

        if (! $this->record->task) {
            return true;
        }

        if ((string) $currentAttempt->id !== (string) $attemptId) {
            return true;
        }

        if ($currentAttempt->status !== ZnunyTicketCreationAttemptStatus::Uncertain) {
            return true;
        }

        if ((string) ($normalized['marker'] ?? '') !== (string) ($currentAttempt->marker ?? '')) {
            return true;
        }

        if (($normalized['eligible'] ?? false) === false) {
            return true;
        }

        if ((string) ($normalized['attempt_id'] ?? '') !== (string) $attemptId) {
            return true;
        }

        if ((string) ($normalized['run_id'] ?? '') !== (string) $this->record->id) {
            return true;
        }

        if ((string) ($normalized['task_id'] ?? '') !== (string) $this->record->task->id) {
            return true;
        }

        return false;
    }

    private function clearReviewState(): void
    {
        $this->reviewContext = [];
        $this->lookupStatus = null;
        $this->lookupMatches = [];
        $this->lastRecheckedAt = null;
    }

    private function safelyReloadPersistentContext(ScheduledZnunyTicketCreationAttemptManualReviewService $reviewService): bool
    {
        try {
            $this->record->refresh();
            $this->record->load(['task', 'latestZnunyTicketCreationAttempt']);

            $attempt = $this->record->latestZnunyTicketCreationAttempt;

            if (! $attempt) {
                $this->clearReviewState();

                return false;
            }

            $rawContext = $reviewService->inspect($attempt->id);
            $normalized = $this->normalizeServiceContext($rawContext);
            $this->applyReviewContext($normalized, false);

            return true;
        } catch (\Throwable) {
            $this->clearReviewState();

            return false;
        }
    }

    private function reloadPersistentContext(ScheduledZnunyTicketCreationAttemptManualReviewService $reviewService): void
    {
        $this->record->refresh();
        $this->record->load(['task', 'latestZnunyTicketCreationAttempt']);

        $attempt = $this->record->latestZnunyTicketCreationAttempt;

        if (! $attempt) {
            throw new NotFoundHttpException('No scheduled run attempt found for this task run.');
        }

        $rawContext = $reviewService->inspect($attempt->id);
        $normalized = $this->normalizeServiceContext($rawContext);
        $this->applyReviewContext($normalized, false);
    }

    private function applyReviewContext(array $context, bool $freshRecheck): void
    {
        $this->reviewContext = $context;
        $this->lookupStatus = $context['lookup_status'];
        $this->lookupMatches = $context['matches'];
        $this->lastRecheckedAt = $freshRecheck ? now()->toDateTimeString() : null;
    }

    private function normalizeServiceContext(array $rawContext): array
    {
        $normalized = $rawContext;

        $status = $rawContext['lookup_status'] ?? null;
        $normalized['lookup_status'] = $status instanceof BackedEnum ? (string) $status->value : (is_string($status) ? $status : null);

        $attemptStatus = $rawContext['attempt_status'] ?? null;
        $normalized['attempt_status'] = $attemptStatus instanceof BackedEnum ? (string) $attemptStatus->value : (is_string($attemptStatus) ? $attemptStatus : null);

        $normalized['matches'] = is_array($rawContext['matches'] ?? null) ? $rawContext['matches'] : [];

        $normalized['found'] = (bool) ($rawContext['found'] ?? false);
        $normalized['eligible'] = (bool) ($rawContext['eligible'] ?? false);
        $normalized['resolved'] = (bool) ($rawContext['resolved'] ?? false);
        $normalized['refresh_attempted'] = isset($rawContext['refresh_attempted']) ? (bool) $rawContext['refresh_attempted'] : null;
        $normalized['refresh_succeeded'] = isset($rawContext['refresh_succeeded']) ? (bool) $rawContext['refresh_succeeded'] : null;
        $normalized['refresh_exit_code'] = isset($rawContext['refresh_exit_code']) ? (int) $rawContext['refresh_exit_code'] : null;

        unset($normalized['reason']);

        return $normalized;
    }

    protected function notifyRecheckResult(): void
    {
        switch ($this->lookupStatus) {
            case ScheduledZnunyTicketMarkerLookupStatus::Found->value:
                Notification::make()
                    ->title(__('scheduled_znuny_task_runs.review.notifications.found.title'))
                    ->body(__('scheduled_znuny_task_runs.review.notifications.found.body'))
                    ->success()
                    ->send();
                break;
            case ScheduledZnunyTicketMarkerLookupStatus::Multiple->value:
                Notification::make()
                    ->title(__('scheduled_znuny_task_runs.review.notifications.multiple.title'))
                    ->body(__('scheduled_znuny_task_runs.review.notifications.multiple.body'))
                    ->warning()
                    ->send();
                break;
            case ScheduledZnunyTicketMarkerLookupStatus::NotFound->value:
                Notification::make()
                    ->title(__('scheduled_znuny_task_runs.review.notifications.not_found.title'))
                    ->body(__('scheduled_znuny_task_runs.review.notifications.not_found.body'))
                    ->warning()
                    ->send();
                break;
            case ScheduledZnunyTicketMarkerLookupStatus::Unavailable->value:
                Notification::make()
                    ->title(__('scheduled_znuny_task_runs.review.notifications.unavailable.title'))
                    ->body(__('scheduled_znuny_task_runs.review.notifications.unavailable.body'))
                    ->danger()
                    ->send();
                break;
        }
    }
}
