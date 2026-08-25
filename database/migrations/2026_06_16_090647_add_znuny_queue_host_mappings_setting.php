<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('settings')->insert([
            'key' => 'znuny_queue_host_mappings',
            'value' => '[]',
            'type' => 'json',
            'description' => 'Mapping of primary Zabbix host prefixes to Znuny queues. Used as a fallback when the primary queue candidate is not found.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'znuny_queue_host_mappings')->delete();
    }
};
