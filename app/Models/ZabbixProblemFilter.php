<?php

namespace App\Models;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

class ZabbixProblemFilter extends Model
{
    protected $fillable = [
        'name',
        'enabled',
        'field',
        'match_type',
        'pattern',
        'case_sensitive',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'case_sensitive' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (ZabbixProblemFilter $filter) {
            AuditLogger::log(
                'zabbix_problem_filter.created',
                'zabbix_problem_filter',
                $filter->id,
                $filter->only([
                    'id', 'name', 'enabled', 'field', 'match_type', 'pattern', 'case_sensitive', 'description',
                ])
            );
        });

        static::updated(function (ZabbixProblemFilter $filter) {
            $changes = [];
            foreach ($filter->getChanges() as $key => $newValue) {
                if ($key === 'updated_at' || $key === 'created_at') {
                    continue;
                }
                $changes[$key] = [
                    'old' => $filter->getOriginal($key),
                    'new' => $newValue,
                ];
            }

            AuditLogger::log(
                'zabbix_problem_filter.updated',
                'zabbix_problem_filter',
                $filter->id,
                [
                    'id' => $filter->id,
                    'name' => $filter->name,
                    'changes' => $changes,
                ]
            );
        });

        static::deleted(function (ZabbixProblemFilter $filter) {
            AuditLogger::log(
                'zabbix_problem_filter.deleted',
                'zabbix_problem_filter',
                $filter->id,
                $filter->only([
                    'id', 'name', 'enabled', 'field', 'match_type', 'pattern', 'case_sensitive', 'description',
                ])
            );
        });
    }
}
