<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZnunyOwnerSuggestionObservation extends Model
{
    protected $fillable = [
        'problem_name',
        'normalized_problem_key',
        'queue_name',
        'owner_id',
        'owner_login',
        'zabbix_event_id',
        'zabbix_host_name',
        'customer_user_login',
        'znuny_ticket_id',
        'created_by_user_id',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
