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
        $oldSetting = Setting::where('key', 'manual_ticket_auto_close_enabled')->first();

        $mode = 'execute';
        if ($oldSetting && $oldSetting->value === 'false') {
            $mode = 'disabled';
        }

        Setting::updateOrCreate(
            ['key' => 'manual_ticket_auto_close_schedule_mode'],
            [
                'value' => $mode,
                'type' => 'string',
                'description' => 'Scheduler mode for manual ticket auto-close (disabled, dry_run, execute).',
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Setting::where('key', 'manual_ticket_auto_close_schedule_mode')->delete();
    }
};
