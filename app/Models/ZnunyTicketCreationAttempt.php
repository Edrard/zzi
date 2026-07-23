<?php

namespace App\Models;

use App\Enums\ZnunyTicketCreationAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZnunyTicketCreationAttempt extends Model
{
    protected $fillable = [
        'source_type',
        'source_id',
        'marker',
        'subject_original',
        'subject_sent',
        'status',
        'ticket_id',
        'ticket_number',
        'started_at',
        'finished_at',
        'last_checked_at',
        'check_attempts',
        'error_summary',
        'error_details',
        'payload_snapshot',
        'response_snapshot',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ZnunyTicketCreationAttemptStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'check_attempts' => 'integer',
            'ticket_id' => 'integer',
            'payload_snapshot' => 'array',
            'response_snapshot' => 'array',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
