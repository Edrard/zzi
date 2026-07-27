<?php

namespace App\Services\Znuny;

use App\Enums\ScheduledZnunyTicketMarkerLookupStatus;
use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\ZnunyTicketCreationAttempt;

class ScheduledZnunyTicketCreationAttemptManualReviewService
{
    public function __construct(
        private readonly ScheduledZnunyTicketMarkerLookupService $markerLookup,
        private readonly ScheduledZnunyTicketMarkerRefreshLookupService $refreshLookup
    ) {}

    public function inspect(string|int $attemptId): array
    {
        $context = $this->buildContext($attemptId);

        if (! $context['eligible']) {
            unset($context['attempt_model']);

            return $context;
        }

        /** @var ZnunyTicketCreationAttempt $attempt */
        $attempt = $context['attempt_model'];
        unset($context['attempt_model']);

        $lookupResult = $this->markerLookup->findExactMarker($attempt->marker);

        $context['lookup_status'] = $lookupResult['status'];
        $context['matches'] = $lookupResult['matches'];
        $context['reason'] = $lookupResult['reason'];

        return $context;
    }

    public function recheck(string|int $attemptId): array
    {
        $context = $this->buildContext($attemptId);

        if (! $context['eligible']) {
            unset($context['attempt_model']);

            return $context;
        }

        /** @var ZnunyTicketCreationAttempt $attempt */
        $attempt = $context['attempt_model'];
        unset($context['attempt_model']);

        $lookupResult = $this->refreshLookup->findExactMarkerWithRefresh($attempt);

        return $this->mergeLookupResult($context, $lookupResult);
    }

    public function forceRecheck(string|int $attemptId): array
    {
        $context = $this->buildContext($attemptId);

        if (! $context['eligible']) {
            unset($context['attempt_model']);

            return $context;
        }

        /** @var ZnunyTicketCreationAttempt $attempt */
        $attempt = $context['attempt_model'];
        unset($context['attempt_model']);

        $lookupResult = $this->refreshLookup->refreshAndFindExactMarker($attempt);

        return $this->mergeLookupResult($context, $lookupResult);
    }

    private function mergeLookupResult(array $context, array $lookupResult): array
    {
        $context['lookup_status'] = $lookupResult['status'];
        $context['matches'] = $lookupResult['matches'];
        $context['reason'] = $lookupResult['reason'];

        $context['refresh_attempted'] = $lookupResult['refresh_attempted'] ?? false;
        $context['refresh_succeeded'] = $lookupResult['refresh_succeeded'] ?? false;
        $context['refresh_exit_code'] = $lookupResult['refresh_exit_code'] ?? null;

        return $context;
    }

    private function buildContext(string|int $attemptId): array
    {
        $attempt = ZnunyTicketCreationAttempt::find($attemptId);

        $base = [
            'found' => false,
            'eligible' => false,
            'resolved' => false,
            'attempt_id' => null,
            'attempt_status' => null,
            'source_type' => null,
            'source_id' => null,
            'marker' => null,
            'run_id' => null,
            'run_status' => null,
            'task_id' => null,
            'task_name' => null,
            'stored_ticket_id' => null,
            'stored_ticket_number' => null,
            'lookup_status' => ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
            'matches' => [],
            'reason' => 'Scheduled Znuny ticket creation attempt was not found.',
            'refresh_attempted' => false,
            'refresh_succeeded' => false,
            'refresh_exit_code' => null,
        ];

        if (! $attempt) {
            return $base;
        }

        $base['found'] = true;
        $base['attempt_id'] = $attempt->id;
        $base['attempt_status'] = $attempt->status->value;
        $base['source_type'] = $attempt->source_type;
        $base['source_id'] = $attempt->source_id;
        $base['marker'] = $attempt->marker;
        $base['stored_ticket_id'] = $attempt->ticket_id;
        $base['stored_ticket_number'] = $attempt->ticket_number;
        $base['reason'] = null;

        if ($attempt->source_type !== 'scheduled_run') {
            $base['resolved'] = $this->isResolvedStatus($attempt->status);
            $base['reason'] = 'Only scheduled-run ticket creation attempts support manual resolution.';

            return $base;
        }

        $resolvedWithIdStatuses = [
            ZnunyTicketCreationAttemptStatus::Success,
            ZnunyTicketCreationAttemptStatus::Recovered,
            ZnunyTicketCreationAttemptStatus::ManuallyLinked,
        ];

        if (in_array($attempt->status, $resolvedWithIdStatuses, true)) {
            $hasValidId = $attempt->ticket_id !== null
                && $attempt->ticket_number !== null
                && trim((string) $attempt->ticket_number) !== '';

            if ($hasValidId) {
                $base['resolved'] = true;
                $base['reason'] = 'This attempt is already safely resolved.';
            } else {
                $base['resolved'] = false;
                $base['reason'] = 'Attempt status is resolved but it lacks valid ticket identifiers.';
            }

            return $base;
        }

        if ($attempt->status !== ZnunyTicketCreationAttemptStatus::Uncertain) {
            $base['resolved'] = ($attempt->status === ZnunyTicketCreationAttemptStatus::ResolvedWithoutTicket);
            $base['reason'] = 'Attempt status is not eligible for manual resolution.';

            return $base;
        }

        if ($attempt->marker === null || trim($attempt->marker) === '') {
            $base['reason'] = 'The scheduled ticket creation attempt has no marker.';

            return $base;
        }

        $run = ScheduledZnunyTaskRun::find($attempt->source_id);
        if (! $run) {
            $base['reason'] = 'The Scheduled Znuny task run linked to this attempt was not found.';

            return $base;
        }

        $base['run_id'] = $run->id;
        $base['run_status'] = $run->status;

        $task = ScheduledZnunyTask::find($run->scheduled_znuny_task_id);
        if (! $task) {
            $base['reason'] = 'The Scheduled Znuny task linked to this attempt was not found.';

            return $base;
        }

        $base['task_id'] = $task->id;
        $base['task_name'] = $task->name;

        $base['eligible'] = true;
        $base['attempt_model'] = $attempt;

        return $base;
    }

    private function isResolvedStatus(ZnunyTicketCreationAttemptStatus $status): bool
    {
        return in_array($status, [
            ZnunyTicketCreationAttemptStatus::Success,
            ZnunyTicketCreationAttemptStatus::Recovered,
            ZnunyTicketCreationAttemptStatus::ManuallyLinked,
            ZnunyTicketCreationAttemptStatus::ResolvedWithoutTicket,
        ], true);
    }
}
