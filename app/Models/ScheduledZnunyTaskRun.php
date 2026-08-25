<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ScheduledZnunyTaskRun extends Model
{
    public const RESOLUTION_TYPE_RETRY_CREATED = 'retry_created';

    public const MAX_RETRY_CHAIN_DEPTH = 100;

    protected $guarded = [];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'payload_snapshot' => 'array',
        'response_snapshot' => 'array',
        'resolved_at' => 'datetime',
        'root_run_id' => 'integer',
        'parent_run_id' => 'integer',
        'retry_sequence' => 'integer',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ScheduledZnunyTask::class, 'scheduled_znuny_task_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function manualRetryOfAttempt(): BelongsTo
    {
        return $this->belongsTo(
            ZnunyTicketCreationAttempt::class,
            'manual_retry_of_attempt_id'
        );
    }

    public function latestZnunyTicketCreationAttempt(): HasOne
    {
        return $this->hasOne(ZnunyTicketCreationAttempt::class, 'source_id')
            ->where('source_type', 'scheduled_run')
            ->ofMany(['created_at' => 'max', 'id' => 'max']);
    }

    public function rootRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_run_id');
    }

    public function parentRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    public function childRun(): HasOne
    {
        return $this->hasOne(self::class, 'parent_run_id');
    }

    public function effectiveRoot(): self
    {
        if ($this->root_run_id === null) {
            if ($this->parent_run_id !== null || $this->retry_sequence !== 0) {
                throw new \LogicException("Malformed lineage: Run {$this->id} claims to be a root but has a parent or non-zero retry sequence.");
            }

            return $this;
        }

        if ($this->parent_run_id === null || $this->retry_sequence <= 0) {
            throw new \LogicException("Malformed lineage: Run {$this->id} claims to be a descendant but has no parent or an invalid retry sequence.");
        }

        if ($this->root_run_id === $this->id) {
            throw new \LogicException("Malformed lineage: Run {$this->id} self-references as its own root.");
        }

        $root = $this->rootRun;
        if (! $root) {
            throw new \LogicException("Malformed lineage: Referenced root_run_id {$this->root_run_id} for run {$this->id} does not exist.");
        }

        if ($root->root_run_id !== null || $root->parent_run_id !== null || $root->retry_sequence !== 0) {
            throw new \LogicException("Malformed lineage: Referenced root run {$root->id} is itself a descendant or malformed.");
        }

        return $root;
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function currentLeaf(): self
    {
        $visited = [$this->id => true];
        $current = $this;
        $depth = 0;

        while ($current->childRun) {
            $child = $current->childRun;
            $depth++;

            if ($depth > self::MAX_RETRY_CHAIN_DEPTH) {
                throw new \LogicException("Malformed lineage: Maximum retry chain depth exceeded starting from run {$this->id}.");
            }

            if (isset($visited[$child->id])) {
                throw new \LogicException("Malformed lineage: Cycle detected at run {$child->id}.");
            }

            $visited[$child->id] = true;
            $current = $child;
        }

        return $current;
    }
}
