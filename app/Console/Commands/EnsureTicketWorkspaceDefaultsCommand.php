<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

class EnsureTicketWorkspaceDefaultsCommand extends Command
{
    protected $signature = 'settings:ensure-ticket-workspace-defaults';

    protected $description = 'Safely backfill and migrate Ticket Workspace settings';

    public function handle(): int
    {
        $this->migrateOldKeys();
        $this->ensureDefaults();

        $this->info('Ticket Workspace settings ensured safely.');

        return self::SUCCESS;
    }

    private function migrateOldKeys(): void
    {
        // znuny_ticket_cache_ttl_seconds -> znuny_ticket_cache_ttl_minutes
        $oldTtl = Setting::where('key', 'znuny_ticket_cache_ttl_seconds')->first();
        if ($oldTtl && ! Setting::where('key', 'znuny_ticket_cache_ttl_minutes')->exists()) {
            $minutes = max(1, (int) round(((int) $oldTtl->value) / 60));
            Setting::create([
                'key' => 'znuny_ticket_cache_ttl_minutes',
                'value' => (string) $minutes,
                'type' => 'integer',
            ]);
            $this->info("Migrated active TTL to {$minutes} minutes.");
        }

        // znuny_ticket_cache_closed_ttl_seconds -> znuny_ticket_cache_closed_ttl_minutes
        $oldClosedTtl = Setting::where('key', 'znuny_ticket_cache_closed_ttl_seconds')->first();
        if ($oldClosedTtl && ! Setting::where('key', 'znuny_ticket_cache_closed_ttl_minutes')->exists()) {
            $minutes = max(1, (int) round(((int) $oldClosedTtl->value) / 60));
            Setting::create([
                'key' => 'znuny_ticket_cache_closed_ttl_minutes',
                'value' => (string) $minutes,
                'type' => 'integer',
            ]);
            $this->info("Migrated closed TTL to {$minutes} minutes.");
        }

        // znuny_ticket_cache_active_state_types -> znuny_ticket_workspace_active_state_type_ids
        $oldStateTypes = Setting::where('key', 'znuny_ticket_cache_active_state_types')->first();
        if ($oldStateTypes && ! Setting::where('key', 'znuny_ticket_workspace_active_state_type_ids')->exists()) {
            $rawStrings = array_map('trim', explode(',', $oldStateTypes->value));
            $mappedIds = [];
            $stringToId = [
                'new' => 'new',
                'open' => 'open',
                'pending reminder' => 'pending_reminder',
                'pending auto' => 'pending_auto',
                'closed' => 'closed',
                'merged' => 'merged',
            ];
            foreach ($rawStrings as $str) {
                $strLower = strtolower($str);
                if (isset($stringToId[$strLower])) {
                    $mappedIds[] = $stringToId[$strLower];
                }
            }
            if (empty($mappedIds)) {
                $mappedIds = ['new', 'open', 'pending_reminder', 'pending_auto']; // fallback default
            }

            Setting::create([
                'key' => 'znuny_ticket_workspace_active_state_type_ids',
                'value' => json_encode(array_values(array_unique($mappedIds))),
                'type' => 'json',
            ]);
            $this->info('Migrated active state types to IDs.');
        }
    }

    private function ensureDefaults(): void
    {
        $defaults = [
            'znuny_ticket_workspace_enabled' => ['value' => 'true', 'type' => 'boolean'],
            'znuny_ticket_cache_refresh_interval_minutes' => ['value' => '5', 'type' => 'integer'],
            'znuny_ticket_cache_max_pages_per_run' => ['value' => '3', 'type' => 'integer'],
            'znuny_ticket_cache_ttl_minutes' => ['value' => '15', 'type' => 'integer'],
            'znuny_ticket_cache_closed_ttl_minutes' => ['value' => '1440', 'type' => 'integer'],
            'znuny_ticket_cache_default_limit' => ['value' => '50', 'type' => 'integer'],
            'znuny_ticket_workspace_active_state_type_ids' => ['value' => '["new","open","pending_reminder","pending_auto"]', 'type' => 'json'],
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
