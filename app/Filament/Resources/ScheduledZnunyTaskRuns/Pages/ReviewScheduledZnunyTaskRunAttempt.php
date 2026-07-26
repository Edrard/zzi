<?php

namespace App\Filament\Resources\ScheduledZnunyTaskRuns\Pages;

use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Filament\Resources\ScheduledZnunyTaskRuns\ScheduledZnunyTaskRunResource;
use App\Services\Znuny\ScheduledZnunyTicketCreationAttemptManualReviewService;
use BackedEnum;
use Filament\Actions\Action;
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
    public ?string $lookupReason = null;
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
            Action::make('recheck')
                ->label(__('scheduled_znuny_task_runs.review.actions.recheck'))
                ->icon('heroicon-o-arrow-path')
                ->action('recheck')
                ->visible(function () {
                    $attempt = $this->record->latestZnunyTicketCreationAttempt()->first();
                    return $attempt && $attempt->status === ZnunyTicketCreationAttemptStatus::Uncertain;
                }),
        ];
    }

    public function recheck(ScheduledZnunyTicketCreationAttemptManualReviewService $reviewService): void
    {
        $attempt = $this->record->latestZnunyTicketCreationAttempt()->first();

        if (! $attempt || $attempt->status !== ZnunyTicketCreationAttemptStatus::Uncertain) {
            $this->record->refresh();
            $this->record->load(['task', 'latestZnunyTicketCreationAttempt']);
            $currentAttempt = $this->record->latestZnunyTicketCreationAttempt;

            if ($currentAttempt) {
                $this->reloadPersistentContext($reviewService);
            } else {
                $this->clearReviewState();
            }

            Notification::make()
                ->title(__('scheduled_znuny_task_runs.review.notifications.changed.title'))
                ->body(__('scheduled_znuny_task_runs.review.notifications.changed.body'))
                ->warning()
                ->send();
            return;
        }

        $attemptId = $attempt->id;
        $rawContext = $reviewService->recheck($attemptId);
        $normalized = $this->normalizeServiceContext($rawContext);

        $this->record->refresh();
        $this->record->load(['task', 'latestZnunyTicketCreationAttempt']);
        $currentAttempt = $this->record->latestZnunyTicketCreationAttempt;

        if ($this->hasConcurrentStateChange($normalized, $attemptId, $currentAttempt)) {
            if ($currentAttempt) {
                $this->reloadPersistentContext($reviewService);
            } else {
                $this->clearReviewState();
            }

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
        $this->lookupReason = null;
        $this->lookupMatches = [];
        $this->lastRecheckedAt = null;
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
        $this->lookupReason = $context['reason'];
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
        $normalized['reason'] = is_string($rawContext['reason'] ?? null) ? $rawContext['reason'] : null;

        $normalized['found'] = (bool) ($rawContext['found'] ?? false);
        $normalized['eligible'] = (bool) ($rawContext['eligible'] ?? false);
        $normalized['resolved'] = (bool) ($rawContext['resolved'] ?? false);
        $normalized['refresh_attempted'] = isset($rawContext['refresh_attempted']) ? (bool) $rawContext['refresh_attempted'] : null;
        $normalized['refresh_succeeded'] = isset($rawContext['refresh_succeeded']) ? (bool) $rawContext['refresh_succeeded'] : null;
        $normalized['refresh_exit_code'] = isset($rawContext['refresh_exit_code']) ? (int) $rawContext['refresh_exit_code'] : null;

        return $normalized;
    }

    protected function notifyRecheckResult(): void
    {
        switch ($this->lookupStatus) {
            case 'Found':
                Notification::make()
                    ->title(__('scheduled_znuny_task_runs.review.notifications.found.title'))
                    ->body(__('scheduled_znuny_task_runs.review.notifications.found.body'))
                    ->success()
                    ->send();
                break;
            case 'Multiple':
                Notification::make()
                    ->title(__('scheduled_znuny_task_runs.review.notifications.multiple.title'))
                    ->body(__('scheduled_znuny_task_runs.review.notifications.multiple.body'))
                    ->warning()
                    ->send();
                break;
            case 'NotFound':
                Notification::make()
                    ->title(__('scheduled_znuny_task_runs.review.notifications.not_found.title'))
                    ->body(
                        $this->lookupReason
                        ?: __('scheduled_znuny_task_runs.review.notifications.not_found.body')
                    )
                    ->warning()
                    ->send();
                break;
            case 'Unavailable':
                Notification::make()
                    ->title(__('scheduled_znuny_task_runs.review.notifications.unavailable.title'))
                    ->body(
                        $this->lookupReason
                        ?: __('scheduled_znuny_task_runs.review.notifications.unavailable.body')
                    )
                    ->danger()
                    ->send();
                break;
        }
    }
}
