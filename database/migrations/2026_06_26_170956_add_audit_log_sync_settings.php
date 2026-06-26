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
            [
                'key' => 'zabbix_problem_sync_audit_enabled',
                'value' => 'false',
                'type' => 'boolean',
                'description' => 'Write summary audit records for scheduled Zabbix problem polling.',
            ],
            [
                'key' => 'znuny_ticket_workspace_sync_audit_enabled',
                'value' => 'false',
                'type' => 'boolean',
                'description' => 'Write summary audit records for scheduled Ticket Workspace cache warming.',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::firstOrCreate(
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
            'zabbix_problem_sync_audit_enabled',
            'znuny_ticket_workspace_sync_audit_enabled',
        ])->delete();
    }
};
