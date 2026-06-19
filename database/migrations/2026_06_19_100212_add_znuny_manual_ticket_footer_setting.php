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
        Setting::updateOrCreate(
            ['key' => 'znuny_manual_ticket_footer'],
            [
                'value' => 'Created manually by Zabbix Znuny Integration.',
                'type' => 'string',
                'description' => 'Optional text appended to manually created Znuny tickets. Leave empty to disable.',
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Setting::where('key', 'znuny_manual_ticket_footer')->delete();
    }
};
