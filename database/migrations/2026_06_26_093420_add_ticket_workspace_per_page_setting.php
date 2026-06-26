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
            'key' => 'znuny_ticket_workspace_default_per_page',
            'value' => '50',
            'description' => 'Default tickets per page in Ticket Workspace.',
            'type' => 'integer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->where('key', 'znuny_ticket_workspace_default_per_page')->delete();
    }
};
