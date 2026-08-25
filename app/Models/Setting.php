<?php

namespace App\Models;

use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saved(function () {
            SettingsService::clearAllCaches();
        });

        static::deleted(function () {
            SettingsService::clearAllCaches();
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function () {
                SettingsService::clearAllCaches();
            });
        }
    }
}
