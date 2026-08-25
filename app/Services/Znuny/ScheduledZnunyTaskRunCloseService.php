<?php

namespace App\Services\Znuny;

use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\User;
use App\Models\ZnunyTicketCreationAttempt;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ScheduledZnunyTaskRunCloseService
{
    public function __construct(
        private readonly AuditLogger $auditLogger
    ) {}

    public function close(string|int $runId, User $actor): array
    {
        $normalizedRunId = filter_var($runId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($normalizedRunId === false) {
            return $this->buildResult(false, false, false, null, null, null, null, null, null, 'The selected run ID is invalid.');
        }

        try {
            $result = DB::transaction(function () use ($normalizedRunId) {
                // 1. Resolve expected root without locks
                $selectedRun = ScheduledZnunyTaskRun::find($normalizedRunId);
                if (! $selectedRun) {
                    return ['reason' => 'The selected Scheduled Znuny task run was not found.'];
                }

                $expectedRootId = $selectedRun->root_run_id ?? $selectedRun->id;

                // 2. Lock all declared members of the root chain
                $lockedMembers = ScheduledZnunyTaskRun::where(function ($query) use ($expectedRootId) {
                    $query->where('id', $expectedRootId)
                        ->orWhere('root_run_id', $expectedRootId);
                })
                    ->lockForUpdate()
                    ->orderBy('retry_sequence')
                    ->orderBy('id')
                    ->get();

                $lockedMemberIds = $lockedMembers->pluck('id')->toArray();

                // 3. Lock rogue children that point into the chain but declare wrong root
                $rogueChildren = ScheduledZnunyTaskRun::whereIn('parent_run_id', $lockedMemberIds)
                    ->whereNotIn('id', $lockedMemberIds)
                    ->lockForUpdate()
                    ->get();

                if ($rogueChildren->isNotEmpty()) {
                    return ['reason' => 'A child outside the declared member set points to a chain member.'];
                }

                // 4. Validate root
                $root = $lockedMembers->firstWhere('id', $expectedRootId);
                if (! $root) {
                    return ['reason' => 'The locked root run is invalid or no longer exists.'];
                }
                if ($root->root_run_id !== null || $root->parent_run_id !== null || $root->retry_sequence !== 0) {
                    return ['reason' => 'The locked root run is not a valid root.'];
                }

                $parentMap = [];
                $childrenCountMap = [];
                $taskId = $root->scheduled_znuny_task_id;

                // 5. Validate declared members
                foreach ($lockedMembers as $member) {
                    if ($member->scheduled_znuny_task_id !== $taskId) {
                        return ['reason' => 'A member belongs to a different scheduled_znuny_task_id.'];
                    }

                    if ($member->id !== $root->id) {
                        if ($member->root_run_id !== $root->id) {
                            return ['reason' => 'A declared member has an incorrect root_run_id.'];
                        }
                        if ($member->parent_run_id === null) {
                            return ['reason' => 'A declared non-root member has a null parent_run_id.'];
                        }
                        if (! in_array($member->parent_run_id, $lockedMemberIds, true)) {
                            return ['reason' => 'A declared member has a parent outside the locked member set.'];
                        }

                        $parent = $lockedMembers->firstWhere('id', $member->parent_run_id);
                        if ($member->retry_sequence !== $parent->retry_sequence + 1) {
                            return ['reason' => 'Child retry_sequence mismatch.'];
                        }

                        $parentMap[$member->id] = $member->parent_run_id;
                        $childrenCountMap[$member->parent_run_id] = ($childrenCountMap[$member->parent_run_id] ?? 0) + 1;

                        if ($childrenCountMap[$member->parent_run_id] > 1) {
                            return ['reason' => 'Fork detected: a member has more than one child.'];
                        }
                    }
                }

                // 6. Verify path from root
                $visited = [$root->id => true];
                $current = $root;
                $depth = 0;

                while (true) {
                    $childId = null;
                    foreach ($parentMap as $cId => $pId) {
                        if ($pId === $current->id) {
                            $childId = $cId;
                            break;
                        }
                    }

                    if (! $childId) {
                        break;
                    }

                    if (isset($visited[$childId])) {
                        return ['reason' => 'Cycle detected.'];
                    }

                    $visited[$childId] = true;
                    $current = $lockedMembers->firstWhere('id', $childId);
                    $depth++;

                    if ($depth > ScheduledZnunyTaskRun::MAX_RETRY_CHAIN_DEPTH) {
                        return ['reason' => 'Maximum retry chain depth exceeded.'];
                    }
                }

                if (count($visited) !== count($lockedMembers)) {
                    return ['reason' => 'Detached member detected in the declared chain.'];
                }

                $leafRunId = $current->id;

                if (! isset($visited[$normalizedRunId])) {
                    return ['reason' => 'The originally selected run is no longer part of this chain.'];
                }

                if ($normalizedRunId !== $leafRunId) {
                    return ['reason' => 'The selected run is not the current leaf of this chain.'];
                }

                $technicalStatus = $current->status;
                $resolutionType = $current->resolution_type;

                // 7. Leaf behavior
                if ($current->isResolved()) {
                    if ($current->resolution_type === 'manual_closed') {
                        return [
                            'success' => true,
                            'closed' => true,
                            'transitioned' => false,
                            'existing' => true,
                            'root_run_id' => $root->id,
                            'leaf_run_id' => $leafRunId,
                            'task_id' => $taskId,
                            'technical_status' => $technicalStatus,
                            'resolution_type' => $resolutionType,
                        ];
                    }

                    return ['reason' => 'The selected run is already resolved by another mechanism.'];
                }

                if (! in_array($current->status, ['failed', 'uncertain'], true)) {
                    return ['reason' => 'The selected run is not eligible for manual close.'];
                }

                // 8. Lock task and attempt
                $lockedTask = ScheduledZnunyTask::lockForUpdate()->find($taskId);
                if (! $lockedTask) {
                    return ['reason' => 'The associated scheduled task was not found.'];
                }

                $newestAttempt = ZnunyTicketCreationAttempt::where('source_type', 'scheduled_run')
                    ->where('source_id', $current->id)
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                $previousAttemptStatus = null;
                $newAttemptStatus = null;
                $attemptId = null;

                if ($newestAttempt) {
                    $attemptId = $newestAttempt->id;
                    $statusValue = $newestAttempt->status->value ?? $newestAttempt->status;
                    $previousAttemptStatus = $statusValue;

                    if (in_array($statusValue, [
                        ZnunyTicketCreationAttemptStatus::Success->value,
                        ZnunyTicketCreationAttemptStatus::Recovered->value,
                        ZnunyTicketCreationAttemptStatus::ManuallyLinked->value,
                    ], true)) {
                        return ['reason' => 'The newest attempt is already successful, recovered, or manually linked.'];
                    }

                    if (in_array($statusValue, [
                        ZnunyTicketCreationAttemptStatus::Preparing->value,
                        ZnunyTicketCreationAttemptStatus::Sending->value,
                        ZnunyTicketCreationAttemptStatus::Orphaned->value,
                    ], true)) {
                        return ['reason' => 'The newest attempt is in an unsafe or ambiguous state.'];
                    }

                    if ($statusValue === ZnunyTicketCreationAttemptStatus::Uncertain->value) {
                        $newestAttempt->status = ZnunyTicketCreationAttemptStatus::ResolvedWithoutTicket;
                        if ($newestAttempt->finished_at === null) {
                            $newestAttempt->finished_at = Carbon::now('UTC');
                        }
                        $newestAttempt->save();
                        $newAttemptStatus = ZnunyTicketCreationAttemptStatus::ResolvedWithoutTicket->value;
                    } elseif ($statusValue === ZnunyTicketCreationAttemptStatus::ConfirmedFailed->value || $statusValue === ZnunyTicketCreationAttemptStatus::ResolvedWithoutTicket->value) {
                        $newAttemptStatus = $statusValue;
                    } else {
                        return ['reason' => 'The newest attempt has an unexpected status.'];
                    }
                }

                // 9. Perform close transition
                $now = Carbon::now('UTC');

                $current->resolved_at = $now;
                $current->resolution_type = 'manual_closed';
                $current->save();

                $lockedTask->last_status = 'success';
                $lockedTask->last_error_summary = null;
                $lockedTask->last_ticket_id = $current->ticket_id;
                $lockedTask->last_ticket_number = $current->ticket_number;
                $lockedTask->save();

                return [
                    'success' => true,
                    'closed' => true,
                    'transitioned' => true,
                    'existing' => false,
                    'root_run_id' => $root->id,
                    'leaf_run_id' => $leafRunId,
                    'task_id' => $taskId,
                    'technical_status' => $technicalStatus,
                    'resolution_type' => 'manual_closed',
                    'attempt_id' => $attemptId,
                    'previous_attempt_status' => $previousAttemptStatus,
                    'new_attempt_status' => $newAttemptStatus,
                ];
            });
        } catch (Throwable $e) {
            Log::error('Transaction error during scheduled Znuny run close.', [
                'run_id' => $normalizedRunId,
            ]);
            $result = ['reason' => 'A transaction error occurred during close.'];
        }

        if (isset($result['success']) && $result['success']) {
            if ($result['transitioned']) {
                try {
                    $this->auditLogger->log(
                        action: 'scheduled_znuny_run_manually_closed',
                        entityType: 'ScheduledZnunyTaskRun',
                        entityId: $result['leaf_run_id'],
                        context: [
                            'task_id' => $result['task_id'],
                            'root_run_id' => $result['root_run_id'],
                            'leaf_run_id' => $result['leaf_run_id'],
                            'technical_status' => $result['technical_status'],
                            'resolution_type' => $result['resolution_type'],
                            'attempt_id' => $result['attempt_id'] ?? null,
                            'previous_attempt_status' => $result['previous_attempt_status'] ?? null,
                            'new_attempt_status' => $result['new_attempt_status'] ?? null,
                            'actor_id' => $actor->id,
                        ],
                        user: $actor
                    );
                } catch (Throwable $e) {
                    Log::error('Audit log failed for manual close.', [
                        'leaf_run_id' => $result['leaf_run_id'],
                    ]);
                }
            }

            return $this->buildResult(
                closed: $result['closed'],
                transitioned: $result['transitioned'],
                existing: $result['existing'],
                runId: $normalizedRunId,
                rootRunId: $result['root_run_id'],
                leafRunId: $result['leaf_run_id'],
                taskId: $result['task_id'],
                technicalStatus: $result['technical_status'],
                resolutionType: $result['resolution_type'],
                reason: null
            );
        }

        return $this->buildResult(
            closed: false,
            transitioned: false,
            existing: false,
            runId: $normalizedRunId,
            rootRunId: null,
            leafRunId: null,
            taskId: null,
            technicalStatus: null,
            resolutionType: null,
            reason: $result['reason'] ?? 'An unexpected error occurred during close.'
        );
    }

    private function buildResult(
        bool $closed,
        bool $transitioned,
        bool $existing,
        int|string|null $runId,
        int|string|null $rootRunId,
        int|string|null $leafRunId,
        int|string|null $taskId,
        ?string $technicalStatus,
        ?string $resolutionType,
        ?string $reason
    ): array {
        return [
            'closed' => $closed,
            'transitioned' => $transitioned,
            'existing' => $existing,
            'run_id' => $runId,
            'root_run_id' => $rootRunId,
            'leaf_run_id' => $leafRunId,
            'task_id' => $taskId,
            'technical_status' => $technicalStatus,
            'resolution_type' => $resolutionType,
            'reason' => $reason,
        ];
    }
}
