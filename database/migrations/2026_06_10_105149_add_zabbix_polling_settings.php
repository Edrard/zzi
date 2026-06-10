<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $settings = [
            ['key' => 'zabbix_poll_interval_minutes', 'value' => '1', 'type' => 'integer', 'description' => 'Zabbix polling interval in minutes'],
            ['key' => 'zabbix_problem_cache_ttl_minutes', 'value' => '3', 'type' => 'integer', 'description' => 'Redis TTL for cached Zabbix problems in minutes'],
            ['key' => 'zabbix_problem_limit', 'value' => '100', 'type' => 'integer', 'description' => 'Maximum number of Zabbix problems to fetch per poll'],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Setting::whereIn('key', [
            'zabbix_poll_interval_minutes',
            'zabbix_problem_cache_ttl_minutes',
            'zabbix_problem_limit',
        ])->delete();
    }
};
