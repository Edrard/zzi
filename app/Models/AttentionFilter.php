<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttentionFilter extends Model
{
    protected $fillable = [
        'name',
        'pattern',
        'enabled',
        'description',
    ];
}
