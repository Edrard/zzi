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
            ['key' => 'znuny_queue_cache_ttl_minutes', 'value' => '15', 'type' => 'integer', 'description' => 'How long Znuny queue lists are cached. 0 disables this cache.'],
            ['key' => 'znuny_agent_cache_ttl_minutes', 'value' => '15', 'type' => 'integer', 'description' => 'How long Znuny agent lists are cached. 0 disables this cache.'],
            ['key' => 'znuny_ticket_snapshot_cache_ttl_minutes', 'value' => '5', 'type' => 'integer', 'description' => 'How long linked ticket snapshots may be cached. 0 disables this cache.'],
            ['key' => 'znuny_linked_ticket_sync_interval_minutes', 'value' => '5', 'type' => 'integer', 'description' => 'How often linked Znuny tickets should be synchronized by the scheduler.'],
            ['key' => 'znuny_linked_ticket_sync_batch_size', 'value' => '50', 'type' => 'integer', 'description' => 'Maximum linked tickets to synchronize per run. 0 means all eligible tickets.'],
            ['key' => 'znuny_detailed_sync_audit_enabled', 'value' => 'false', 'type' => 'boolean', 'description' => 'When enabled, detailed ticket sync events are written to the audit log. Keep disabled unless debugging.'],
            ['key' => 'manual_ticket_auto_close_enabled', 'value' => 'true', 'type' => 'boolean', 'description' => 'Automatically close manually created linked tickets after the Zabbix problem stays resolved long enough.'],
            ['key' => 'manual_ticket_flap_threshold', 'value' => '3', 'type' => 'integer', 'description' => 'Number of repeated active/resolved cycles before a linked problem is considered flapping. 0 disables flapping detection.'],
            ['key' => 'manual_ticket_extra_flapping_delay_hours', 'value' => '4', 'type' => 'integer', 'description' => 'Additional close delay added after flapping is detected for a linked manual ticket.'],
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
            'znuny_queue_cache_ttl_minutes',
            'znuny_agent_cache_ttl_minutes',
            'znuny_ticket_snapshot_cache_ttl_minutes',
            'znuny_linked_ticket_sync_interval_minutes',
            'znuny_linked_ticket_sync_batch_size',
            'znuny_detailed_sync_audit_enabled',
            'manual_ticket_auto_close_enabled',
            'manual_ticket_flap_threshold',
            'manual_ticket_extra_flapping_delay_hours',
        ])->delete();
    }
};
