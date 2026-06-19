<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZabbixTicket extends Model
{
    protected $fillable = [
        'zabbix_event_id',
        'zabbix_trigger_id',
        'zabbix_host_id',
        'zabbix_host_name',
        'zabbix_problem_name',
        'zabbix_severity',
        'zabbix_started_at',
        'creation_source',
        'znuny_ticket_id',
        'znuny_ticket_number',
        'znuny_queue_id',
        'znuny_queue_name',
        'znuny_owner_id',
        'znuny_owner_name',
        'znuny_state_id',
        'znuny_state_name',
        'znuny_ticket_state_type',
        'znuny_priority',
        'znuny_priority_id',
        'znuny_ticket_changed_at',
        'znuny_ticket_last_checked_at',
        'znuny_ticket_last_synced_at',
        'znuny_ticket_sync_error',
        'znuny_ticket_snapshot_hash',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'zabbix_severity' => 'integer',
            'zabbix_started_at' => 'datetime',
            'znuny_ticket_id' => 'integer',
            'znuny_queue_id' => 'integer',
            'znuny_owner_id' => 'integer',
            'znuny_state_id' => 'integer',
            'znuny_priority_id' => 'integer',
            'znuny_ticket_changed_at' => 'datetime',
            'znuny_ticket_last_checked_at' => 'datetime',
            'znuny_ticket_last_synced_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
