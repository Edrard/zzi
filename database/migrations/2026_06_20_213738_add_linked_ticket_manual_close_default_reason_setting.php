<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'linked_ticket_manual_close_default_reason',
            'value' => 'Manual close from Linked Tickets UI.',
            'type' => 'string',
            'description' => 'Default reason used when an operator manually closes a linked Znuny ticket from the Linked Tickets details modal.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'linked_ticket_manual_close_default_reason')->delete();
    }
};
