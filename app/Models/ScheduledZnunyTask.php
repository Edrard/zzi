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
     * Get an array of missing or invalid requirements preventing the task from being scheduled.
     */
    public function missingSchedulingRequirements(): array
    {
        $missing = [];

        if (empty($this->cron_expression)) {
            $missing[] = 'Cron expression is missing';
        } elseif (! app(CronService::class)->isValid($this->cron_expression)) {
            $missing[] = 'Cron expression is invalid';
        }

        if (empty($this->timezone)) {
            $missing[] = 'Timezone is missing';
        }

        if (empty($this->queue_name)) {
            $missing[] = 'Queue is missing';
        }

        if (empty($this->owner_id)) {
            $missing[] = 'Owner is missing';
        }

        if (empty($this->customer_user_login)) {
            $missing[] = 'Customer User is missing';
        }

        if (empty($this->subject)) {
            $missing[] = 'Ticket Subject is missing';
        }

        if (empty($this->body)) {
            $missing[] = 'Ticket Body is missing';
        }

        return $missing;
    }

    /**
     * Determine if the task has all required fields to be enabled and queued for execution.
     */
    public function isCompleteForScheduling(): bool
    {
        return empty($this->missingSchedulingRequirements());
    }
}
