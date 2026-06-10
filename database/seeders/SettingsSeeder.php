<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            ['key' => 'retention_resolved_days', 'value' => '90', 'type' => 'integer', 'description' => 'Days to keep resolved problems'],
            ['key' => 'retention_closed_tickets_days', 'value' => '180', 'type' => 'integer', 'description' => 'Days to keep closed tickets'],
            ['key' => 'retention_action_logs_days', 'value' => '365', 'type' => 'integer', 'description' => 'Days to keep action logs'],
            ['key' => 'retention_failed_jobs_days', 'value' => '30', 'type' => 'integer', 'description' => 'Days to keep failed jobs'],
            ['key' => 'retention_statistics_days', 'value' => '730', 'type' => 'integer', 'description' => 'Days to keep daily statistics'],
            ['key' => 'cleanup_enabled', 'value' => 'true', 'type' => 'boolean', 'description' => 'Enable automated cleanup'],
            ['key' => 'cleanup_batch_size', 'value' => '1000', 'type' => 'integer', 'description' => 'Records to delete per cleanup batch'],
            ['key' => 'default_close_delay_hours', 'value' => '4', 'type' => 'integer', 'description' => 'Hours before auto-closing tickets'],
            ['key' => 'default_reopen_window_hours', 'value' => '24', 'type' => 'integer', 'description' => 'Hours window to reopen tickets'],
            ['key' => 'zabbix_api_url', 'value' => '', 'type' => 'string', 'description' => 'Zabbix API endpoint URL'],
            ['key' => 'zabbix_api_token', 'value' => '', 'type' => 'string', 'description' => 'Zabbix API token'],
            ['key' => 'zabbix_api_timeout', 'value' => '15', 'type' => 'integer', 'description' => 'Zabbix API request timeout in seconds'],
            ['key' => 'zabbix_api_verify_ssl', 'value' => 'true', 'type' => 'boolean', 'description' => 'Verify Zabbix API SSL certificate'],
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
}
