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
            'key' => 'znuny_agent_exclude_logins',
            'value' => "root@localhost\nzabbix.integration",
            'type' => 'string',
            'description' => 'Znuny agent logins that must not be selectable as ticket owners. Put one login per line.',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->where('key', 'znuny_agent_exclude_logins')->delete();
    }
};
