<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'manual_ticket_reopen_note_template',
            'value' => 'Reopening this ticket because the linked Zabbix problem became active again within the configured reopen window.',
            'type' => 'string',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->where('key', 'manual_ticket_reopen_note_template')->delete();
    }
};
