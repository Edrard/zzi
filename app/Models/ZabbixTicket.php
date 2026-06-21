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
        'znuny_ticket_closed_at',
        'znuny_ticket_sync_error',
        'znuny_ticket_snapshot_hash',
        'created_by',
        'manual_lifecycle_status',
        'zabbix_problem_is_active',
        'zabbix_problem_last_seen_active_at',
        'zabbix_problem_resolved_at',
        'manual_close_eligible_at',
        'manual_lifecycle_closed_at',
        'manual_reopened_at',
        'manual_flap_count',
        'manual_flapping_detected_at',
        'zabbix_last_counted_flap_event_id',
        'zabbix_last_counted_flap_started_at',
        'manual_last_flap_counted_at',
        'manual_lifecycle_last_checked_at',
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
            'znuny_ticket_closed_at' => 'datetime',
            'created_by' => 'integer',
            'zabbix_problem_is_active' => 'boolean',
            'zabbix_problem_last_seen_active_at' => 'datetime',
            'zabbix_problem_resolved_at' => 'datetime',
            'manual_close_eligible_at' => 'datetime',
            'manual_lifecycle_closed_at' => 'datetime',
            'manual_reopened_at' => 'datetime',
            'manual_flap_count' => 'integer',
            'manual_flapping_detected_at' => 'datetime',
            'zabbix_last_counted_flap_started_at' => 'datetime',
            'manual_last_flap_counted_at' => 'datetime',
            'manual_lifecycle_last_checked_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
