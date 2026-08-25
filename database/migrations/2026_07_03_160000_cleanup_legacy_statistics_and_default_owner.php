<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Delete legacy settings
        $keysToDelete = [
            'znuny_default_agent_id',
            'znuny_default_agent_login',
            'znuny_default_agent_name',
            'retention_statistics_days',
        ];

        DB::table('settings')->whereIn('key', $keysToDelete)->delete();

        // 2. Drop legacy daily_statistics table
        Schema::dropIfExists('daily_statistics');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally conservative/no-op.
        // We cannot reliably restore deleted settings or historical statistics data.
    }
};
