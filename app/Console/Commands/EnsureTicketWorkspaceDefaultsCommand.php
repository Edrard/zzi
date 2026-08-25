<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class EnsureTicketWorkspaceDefaultsCommand extends Command
{
    protected $signature = 'settings:ensure-ticket-workspace-defaults';

    protected $description = 'Safely backfill and migrate Ticket Workspace settings';

    public function handle(): int
    {
        $this->deleteObsoleteKeys();
        $this->ensureDefaults();

        $this->info('Ticket Workspace settings ensured safely.');

        return self::SUCCESS;
    }

    private function deleteObsoleteKeys(): void
    {
        $obsoleteKeys = [
            'znuny_ticket_cache_ttl_seconds',
            'znuny_ticket_cache_closed_ttl_seconds',
            'znuny_ticket_cache_active_state_types',
            'znuny_ticket_cache_closed_ttl_minutes',
        ];

        $deletedCount = Setting::whereIn('key', $obsoleteKeys)->delete();
        if ($deletedCount > 0) {
            SettingsService::clearAllCaches();
            $this->info("Deleted {$deletedCount} obsolete Ticket Workspace setting(s).");
        }
    }

    private function ensureDefaults(): void
    {
        $defaults = [
            'znuny_ticket_workspace_enabled' => ['value' => 'true', 'type' => 'boolean'],
            'znuny_ticket_cache_refresh_interval_minutes' => ['value' => '5', 'type' => 'integer'],
            'znuny_ticket_cache_max_pages_per_run' => ['value' => '3', 'type' => 'integer'],
            'znuny_ticket_cache_ttl_minutes' => ['value' => '10', 'type' => 'integer'],
            'znuny_ticket_cache_default_limit' => ['value' => '50', 'type' => 'integer'],
            'znuny_ticket_workspace_active_state_type_ids' => ['value' => '["new","open","pending_reminder","pending_auto"]', 'type' => 'json'],
            'znuny_closed_ticket_window_days' => ['value' => '30', 'type' => 'integer'],
            'znuny_closed_ticket_small_sync_interval_minutes' => ['value' => '5', 'type' => 'integer'],
            'znuny_closed_ticket_sync_audit_auto_enabled' => ['value' => 'false', 'type' => 'boolean'],
        ];

        foreach ($defaults as $key => $data) {
            if (! Setting::where('key', $key)->exists()) {
                Setting::create([
                    'key' => $key,
                    'value' => $data['value'],
                    'type' => $data['type'],
                ]);
                $this->info("Created default setting: {$key}");
            }
        }
    }
}
