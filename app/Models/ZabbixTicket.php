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
        'znuny_ticket_id',
        'znuny_ticket_number',
        'znuny_queue_id',
        'znuny_queue_name',
        'znuny_owner_id',
        'znuny_owner_name',
        'znuny_state_id',
        'znuny_state_name',
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
            'created_by' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
