<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZnunyOwnerSuggestionStat extends Model
{
    protected $fillable = [
        'normalized_problem_key',
        'queue_name',
        'owner_id',
        'owner_login',
        'score',
        'sample_count',
        'recent_count',
        'old_count',
        'last_seen_at',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:4',
            'sample_count' => 'integer',
            'recent_count' => 'integer',
            'old_count' => 'integer',
            'last_seen_at' => 'datetime',
            'calculated_at' => 'datetime',
        ];
    }
}
