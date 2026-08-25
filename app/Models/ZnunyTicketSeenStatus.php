<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZnunyTicketSeenStatus extends Model
{
    public $timestamps = false;

    protected $table = 'znuny_ticket_seen_statuses';

    protected $fillable = [
        'user_id',
        'znuny_ticket_id',
        'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
