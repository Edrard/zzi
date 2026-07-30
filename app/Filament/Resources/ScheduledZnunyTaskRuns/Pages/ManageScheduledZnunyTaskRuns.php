<?php

namespace App\Filament\Resources\ScheduledZnunyTaskRuns\Pages;

use App\Filament\Resources\ScheduledZnunyTaskRuns\ScheduledZnunyTaskRunResource;
use App\Models\ScheduledZnunyTaskRun;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection;

class ManageScheduledZnunyTaskRuns extends ManageRecords
{
    protected static string $resource = ScheduledZnunyTaskRunResource::class;

    public array $runChainStates = [];

    private string $lastRecordsSignature = '';

    public function getRunChainState(int $runId): array
    {
        return $this->runChainStates[$runId] ?? [
            'valid_chain' => false,
            'current_leaf' => false,
            'historical_member' => false,
            'detached_or_orphan' => false,
            'malformed_chain' => true,
        ];
    }

    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $result = parent::getTableRecords();

        $records = $result instanceof Paginator || $result instanceof CursorPaginator
            ? $result->getCollection()
            : $result;

        if ($records->isEmpty()) {
            $this->runChainStates = [];
            $this->lastRecordsSignature = '';

            return $result;
        }

        $signature = $records->pluck('id')->implode(',');

        if ($signature === $this->lastRecordsSignature) {
            return $result;
        }

        $this->lastRecordsSignature = $signature;
        $this->runChainStates = [];

        $rootIds = [];
        foreach ($records as $record) {
            $rootIds[] = $record->root_run_id ?? $record->id;
        }
        $rootIds = array_unique($rootIds);

        $allRuns = ScheduledZnunyTaskRun::select(
            'id',
            'scheduled_znuny_task_id',
            'root_run_id',
            'parent_run_id',
            'retry_sequence'
        )->get();

        $membersByRoot = [];
        foreach ($allRuns as $run) {
            $rId = $run->root_run_id ?? $run->id;
            $membersByRoot[$rId][] = $run;
        }

        foreach ($rootIds as $rootId) {
            $chainMembers = $membersByRoot[$rootId] ?? [];
            $this->evaluateChainContext($rootId, $chainMembers, $allRuns);
        }

        foreach ($records as $record) {
            if (isset($this->runChainStates[$record->id]) === false) {
                $this->runChainStates[$record->id] = [
                    'valid_chain' => false,
                    'current_leaf' => false,
                    'historical_member' => false,
                    'detached_or_orphan' => true,
                    'malformed_chain' => true,
                ];
            }
        }

        return $result;
    }

    private function evaluateChainContext(int $rootId, array $chainMembers, Collection $allRuns): void
    {
        $isMalformed = false;
        $root = null;
        $membersById = [];
        $taskIds = [];

        foreach ($chainMembers as $m) {
            $membersById[$m->id] = $m;

            $taskId = $m->scheduled_znuny_task_id;
            if ($taskId === null) {
                $taskIds[''] = true;
            } else {
                $taskIds[$taskId] = true;
            }

            if ($m->id === $rootId) {
                $root = $m;
            }
        }

        if ($root === null) {
            $isMalformed = true;
        } else {
            if ($root->root_run_id !== null || $root->parent_run_id !== null || $root->retry_sequence !== 0) {
                $isMalformed = true;
            }
        }

        if (count($taskIds) > 1) {
            $isMalformed = true;
        }

        $childrenByParent = [];
        foreach ($chainMembers as $m) {
            if ($m->id !== $rootId) {
                if ($m->root_run_id !== $rootId || $m->parent_run_id === null) {
                    $isMalformed = true;
                } else {
                    if (isset($membersById[$m->parent_run_id]) === false) {
                        $isMalformed = true;
                    } else {
                        $parent = $membersById[$m->parent_run_id];
                        if ($m->retry_sequence !== $parent->retry_sequence + 1) {
                            $isMalformed = true;
                        }
                    }
                    $childrenByParent[$m->parent_run_id][] = $m->id;
                }
            }
        }

        foreach ($allRuns as $run) {
            if (isset($membersById[$run->parent_run_id]) && isset($membersById[$run->id]) === false) {
                $isMalformed = true;
            }
        }

        foreach ($childrenByParent as $parentId => $children) {
            if (count($children) > 1) {
                $isMalformed = true;
            }
        }

        $reachableIds = [];
        $current = $root;
        $depth = 0;

        if ($root !== null) {
            while ($current !== null) {
                $reachableIds[$current->id] = true;

                if (isset($childrenByParent[$current->id]) === false) {
                    break;
                }
                $children = $childrenByParent[$current->id];
                if (count($children) !== 1) {
                    $isMalformed = true;
                    break;
                }

                $nextId = $children[0];
                if (isset($reachableIds[$nextId])) {
                    $isMalformed = true;
                    break;
                }

                $depth++;
                if ($depth > ScheduledZnunyTaskRun::MAX_RETRY_CHAIN_DEPTH) {
                    $isMalformed = true;
                    break;
                }

                $current = $membersById[$nextId] ?? null;
            }
        }

        if (count($reachableIds) !== count($chainMembers)) {
            $isMalformed = true;
        }

        $currentLeafId = ($root !== null && $isMalformed === false && $current !== null) ? $current->id : null;

        $structuralReachableIds = [];
        $queue = [];
        if ($root !== null) {
            $queue[] = $root->id;
        }

        while (count($queue) > 0) {
            $currId = array_shift($queue);
            if (isset($structuralReachableIds[$currId]) === false) {
                $structuralReachableIds[$currId] = true;
                if (isset($childrenByParent[$currId])) {
                    foreach ($childrenByParent[$currId] as $childId) {
                        $queue[] = $childId;
                    }
                }
            }
        }

        foreach ($chainMembers as $m) {
            $isDetached = false;
            if ($isMalformed) {
                if ($root === null) {
                    $isDetached = true;
                } elseif ($m->parent_run_id !== null && isset($membersById[$m->parent_run_id]) === false) {
                    $isDetached = true;
                } elseif (isset($structuralReachableIds[$m->id]) === false) {
                    $isDetached = true;
                }
            }

            $this->runChainStates[$m->id] = [
                'valid_chain' => $isMalformed === false,
                'current_leaf' => ($isMalformed === false && $m->id === $currentLeafId),
                'historical_member' => ($isMalformed === false && $m->id !== $currentLeafId),
                'detached_or_orphan' => $isDetached,
                'malformed_chain' => $isMalformed,
            ];
        }
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            // No CreateAction for read-only log
        ];
    }

    public function getTitle(): string
    {
        return ScheduledZnunyTaskRunResource::getPluralModelLabel();
    }
}
