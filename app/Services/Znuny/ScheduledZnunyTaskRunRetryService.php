<?php

namespace App\Services\Znuny;

use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ScheduledZnunyTaskRunRetryService
{
    public function __construct(
        private readonly AuditLogger $auditLogger
    ) {}

    public function retry(int|string $runId, ?User $actor = null): array
    {
        $normalizedRunId = filter_var($runId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($normalizedRunId === false) {
            return $this->buildResult(false, false, false, null, null, null, null, 'The selected run ID is invalid.');
        }

        $conflictReason = null;
        $created = false;
        $existing = false;
        $closed = false;
        $retryRunId = null;

        $rootRunId = null;
        $leafRunId = null;

        try {
            DB::transaction(function () use (
                $normalizedRunId,
                $actor,
                &$conflictReason,
                &$created,
                &$existing,
                &$closed,
                &$retryRunId,
                &$rootRunId,
                &$leafRunId
            ) {
                // 1. Resolve expected root without locks
                $selectedRun = ScheduledZnunyTaskRun::find($normalizedRunId);
                if (! $selectedRun) {
                    $conflictReason = 'The selected Scheduled Znuny task run was not found.';

                    return;
                }

                $expectedRootId = $selectedRun->root_run_id ?? $selectedRun->id;

                // 2. Lock the root
                $lockedRoot = ScheduledZnunyTaskRun::lockForUpdate()->find($expectedRootId);
                if (! $lockedRoot) {
                    $conflictReason = 'The locked root run is invalid or no longer exists.';

                    return;
                }

                if ($lockedRoot->root_run_id !== null || $lockedRoot->parent_run_id !== null || $lockedRoot->retry_sequence !== 0) {
                    $conflictReason = 'The locked root run is not a valid root.';

                    return;
                }

                $rootRunId = $lockedRoot->id;

                // 3. Traverse and lock the chain in root-to-leaf order
                $visited = [$lockedRoot->id => true];
                $current = $lockedRoot;
                $depth = 0;

                while (true) {
                    $lockedChild = ScheduledZnunyTaskRun::where('parent_run_id', $current->id)->lockForUpdate()->first();
                    if (! $lockedChild) {
                        break;
                    }

                    $childRunId = $lockedChild->id;
                    $depth++;
                    if ($depth > ScheduledZnunyTaskRun::MAX_RETRY_CHAIN_DEPTH) {
                        $conflictReason = 'Maximum retry chain depth exceeded during locking traversal.';

                        return;
                    }

                    if (isset($visited[$childRunId])) {
                        $conflictReason = 'Cycle detected during locking traversal.';

                        return;
                    }

                    // Validate child
                    if ($lockedChild->root_run_id !== $rootRunId) {
                        $conflictReason = 'Child root_run_id mismatch during locking traversal.';

                        return;
                    }
                    if ($lockedChild->retry_sequence !== $current->retry_sequence + 1) {
                        $conflictReason = 'Child retry_sequence mismatch during locking traversal.';

                        return;
                    }
                    if ($lockedChild->scheduled_znuny_task_id !== $lockedRoot->scheduled_znuny_task_id) {
                        $conflictReason = 'Child scheduled_znuny_task_id mismatch during locking traversal.';

                        return;
                    }

                    $visited[$lockedChild->id] = true;
                    $current = $lockedChild;
                }

                $lockedLeaf = $current;
                $leafRunId = $lockedLeaf->id;

                // 4. Validate selected-run membership
                if (! isset($visited[$normalizedRunId])) {
                    $conflictReason = 'The originally selected run is no longer part of this chain.';

                    return;
                }

                // 5. Leaf behavior
                if (in_array($lockedLeaf->status, ['pending', 'running'], true)) {
                    $existing = true;
                    $retryRunId = $lockedLeaf->id;

                    return;
                }

                if ($lockedLeaf->status === 'success' || $lockedLeaf->isResolved()) {
                    $closed = true;

                    return;
                }

                if (! in_array($lockedLeaf->status, ['failed', 'uncertain'], true)) {
                    $conflictReason = 'The current leaf run is not eligible for retry.';

                    return;
                }

                // Lock task
                $lockedTask = ScheduledZnunyTask::lockForUpdate()->find($lockedLeaf->scheduled_znuny_task_id);
                if (! $lockedTask) {
                    $conflictReason = 'The Scheduled Znuny task linked to this run was not found.';

                    return;
                }

                $scheduledFor = Carbon::now('UTC')->startOfSecond();
                while (ScheduledZnunyTaskRun::where('scheduled_znuny_task_id', $lockedTask->id)
                    ->where('scheduled_for', $scheduledFor->toDateTimeString())
                    ->exists()) {
                    $scheduledFor = $scheduledFor->addSecond();
                }

                $attemptId = null;
                $latestAttempt = $lockedLeaf->latestZnunyTicketCreationAttempt;
                if ($latestAttempt && $latestAttempt->status === ZnunyTicketCreationAttemptStatus::Uncertain) {
                    $alreadyReferenced = ScheduledZnunyTaskRun::where('manual_retry_of_attempt_id', $latestAttempt->id)->exists();
                    if (! $alreadyReferenced) {
                        $attemptId = $latestAttempt->id;
                    }
                }

                $newRun = ScheduledZnunyTaskRun::create([
                    'scheduled_znuny_task_id' => $lockedLeaf->scheduled_znuny_task_id,
                    'task_name_snapshot' => $lockedLeaf->task_name_snapshot,
                    'run_type' => 'manual_retry',
                    'status' => 'pending',
                    'scheduled_for' => $scheduledFor->toDateTimeString(),
                    'created_by' => $actor?->getKey(),
                    'manual_retry_of_attempt_id' => $attemptId,
                    'root_run_id' => $rootRunId,
                    'parent_run_id' => $leafRunId,
                    'retry_sequence' => $lockedLeaf->retry_sequence + 1,
                ]);

                // Mark the replaced leaf
                $lockedLeaf->resolved_at = Carbon::now('UTC');
                $lockedLeaf->resolution_type = ScheduledZnunyTaskRun::RESOLUTION_TYPE_RETRY_CREATED;
                $lockedLeaf->save();

                $retryRunId = $newRun->id;
                $created = true;
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateParentRunException($e)) {
                // If a race condition caused the unique parent constraint to fail,
                // another process just created the child for this leaf.
                // Find it using the leafRunId.
                $existingRetry = ScheduledZnunyTaskRun::where('parent_run_id', $leafRunId)->first();
                if ($existingRetry) {
                    return $this->buildResult(
                        created: false,
                        existing: true,
                        closed: false,
                        selectedRunId: $normalizedRunId,
                        rootRunId: $rootRunId,
                        leafRunId: $leafRunId,
                        retryRunId: $existingRetry->id,
                        reason: null
                    );
                }
            }
            throw $e;
        }

        if ($conflictReason !== null) {
            return $this->buildResult(
                created: false,
                existing: false,
                closed: false,
                selectedRunId: $normalizedRunId,
                rootRunId: $rootRunId,
                leafRunId: $leafRunId,
                retryRunId: null,
                reason: $conflictReason
            );
        }

        if ($closed) {
            return $this->buildResult(
                created: false,
                existing: false,
                closed: true,
                selectedRunId: $normalizedRunId,
                rootRunId: $rootRunId,
                leafRunId: $leafRunId,
                retryRunId: null,
                reason: null
            );
        }

        if ($created) {
            try {
                $this->auditLogger->log(
                    action: 'scheduled_znuny_run_retry_created',
                    entityType: 'ScheduledZnunyTaskRun',
                    entityId: (string) $normalizedRunId,
                    context: [
                        'selected_run_id' => $normalizedRunId,
                        'root_run_id' => $rootRunId,
                        'replaced_leaf_run_id' => $leafRunId,
                        'retry_run_id' => $retryRunId,
                    ],
                    user: $actor,
                    useAuthenticatedUserFallback: false
                );
            } catch (Throwable $e) {
                Log::error('Audit log creation failed after manual retry creation.', [
                    'side-effect type' => 'audit_log',
                    'original run ID' => $normalizedRunId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->buildResult(
            created: $created,
            existing: $existing,
            closed: false,
            selectedRunId: $normalizedRunId,
            rootRunId: $rootRunId,
            leafRunId: $leafRunId,
            retryRunId: $retryRunId,
            reason: null
        );
    }

    private function isDuplicateParentRunException(QueryException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;
        if ($driverCode !== 1062 && $driverCode !== 19) { // 1062 MySQL, 19 SQLite
            return false;
        }

        $errorContext = strtolower(($e->errorInfo[2] ?? '').' '.$e->getMessage());

        return str_contains($errorContext, 'parent_run_id') && str_contains($errorContext, 'unique');
    }

    private function buildResult(
        bool $created,
        bool $existing,
        bool $closed,
        int|string|null $selectedRunId,
        int|string|null $rootRunId,
        int|string|null $leafRunId,
        int|string|null $retryRunId,
        ?string $reason
    ): array {
        return [
            'created' => $created,
            'existing' => $existing,
            'closed' => $closed,
            'selected_run_id' => $selectedRunId,
            'root_run_id' => $rootRunId,
            'leaf_run_id' => $leafRunId,
            'retry_run_id' => $retryRunId,
            'reason' => $reason,
        ];
    }
}
