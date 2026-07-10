<?php

namespace App\Models;

use App\Services\Cron\CronService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScheduledZnunyTask extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ScheduledZnunyTaskRun::class);
    }

    /**
     * Determine if the task has all required fields to be enabled and queued for execution.
     */
    public function isCompleteForScheduling(): bool
    {
        return ! empty($this->cron_expression)
            && app(CronService::class)->isValid($this->cron_expression)
            && ! empty($this->timezone)
            && ! empty($this->queue_name)
            && ! empty($this->owner_login)
            && ! empty($this->customer_user_login)
            && ! empty($this->subject)
            && ! empty($this->body);
    }
}
