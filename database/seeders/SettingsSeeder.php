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
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
