<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledZnunyTaskRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'payload_snapshot' => 'array',
        'response_snapshot' => 'array',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ScheduledZnunyTask::class, 'scheduled_znuny_task_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
