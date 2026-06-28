<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Support\Settings\DefaultSettings;
use Illuminate\Console\Command;

class EnsureSettingsDefaultsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:ensure-settings-defaults';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ensures all default settings exist in the database without overwriting configured values.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Ensuring settings defaults...');

        $obsoleteKeys = [
            'znuny_ticket_cache_closed_ttl_minutes',
        ];

        $deletedCount = Setting::whereIn('key', $obsoleteKeys)->delete();
        if ($deletedCount > 0) {
            $this->info("Deleted {$deletedCount} obsolete setting(s).");
        }

        $defaults = DefaultSettings::all();
        $created = 0;
        $existing = 0;
        $updatedMeta = 0;

        foreach ($defaults as $default) {
            $setting = Setting::where('key', $default['key'])->first();

            if (! $setting) {
                Setting::create([
                    'key' => $default['key'],
                    'value' => $default['value'],
                    'type' => $default['type'],
                    'description' => $default['description'],
                ]);
                $created++;
                $this->line("Created missing setting: {$default['key']}");
            } else {
                $existing++;
                $metaChanged = false;

                // Update metadata if it doesn't match and it's safe to do so
                // Never update the 'value' here
                if ($setting->type !== $default['type']) {
                    $setting->type = $default['type'];
                    $metaChanged = true;
                }

                if ($setting->description !== $default['description']) {
                    $setting->description = $default['description'];
                    $metaChanged = true;
                }

                if ($metaChanged) {
                    $setting->save();
                    $updatedMeta++;
                    $this->line("Updated metadata for setting: {$default['key']}");
                }
            }
        }

        $this->newLine();
        $this->info("Done! Created: {$created}, Existing: {$existing}, Updated Metadata: {$updatedMeta}");

        return self::SUCCESS;
    }
}
