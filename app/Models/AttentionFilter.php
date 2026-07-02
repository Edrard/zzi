<?php

namespace App\Models;

use App\Services\Zabbix\ZabbixAttentionMetadataService;
use Illuminate\Database\Eloquent\Model;

class AttentionFilter extends Model
{
    protected $fillable = [
        'name',
        'pattern',
        'enabled',
        'description',
    ];

    protected static function booted(): void
    {
        $recalculate = function () {
            app(ZabbixAttentionMetadataService::class)->recalculateCachedProblems();
        };

        static::saved($recalculate);
        static::deleted($recalculate);
    }
}
