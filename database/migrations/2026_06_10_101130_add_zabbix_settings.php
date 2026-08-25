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
            ['key' => 'zabbix_api_url', 'value' => '', 'type' => 'string', 'description' => 'Zabbix API endpoint URL'],
            ['key' => 'zabbix_api_token', 'value' => '', 'type' => 'string', 'description' => 'Zabbix API token'],
            ['key' => 'zabbix_api_timeout', 'value' => '15', 'type' => 'integer', 'description' => 'Zabbix API request timeout in seconds'],
            ['key' => 'zabbix_api_verify_ssl', 'value' => 'true', 'type' => 'boolean', 'description' => 'Verify Zabbix API SSL certificate'],
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
            'zabbix_api_url',
            'zabbix_api_token',
            'zabbix_api_timeout',
            'zabbix_api_verify_ssl',
        ])->delete();
    }
};
