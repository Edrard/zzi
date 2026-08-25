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
        DB::table('settings')->whereIn('key', [
            'znuny_queue_cache_ttl_minutes',
            'znuny_agent_cache_ttl_minutes',
            'znuny_lookup_cache_ttl_minutes',
        ])->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->insert([
            [
                'key' => 'znuny_queue_cache_ttl_minutes',
                'value' => '15',
                'type' => 'integer',
                'description' => 'How long Znuny queue lists are cached. 0 disables this cache.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'znuny_agent_cache_ttl_minutes',
                'value' => '15',
                'type' => 'integer',
                'description' => 'How long Znuny agent lists are cached. 0 disables this cache.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'znuny_lookup_cache_ttl_minutes',
                'value' => '60',
                'type' => 'integer',
                'description' => 'Lifetime in minutes for reusable Znuny lookup data such as queue owners, CustomerUsers, states, priorities, types, filtered queues, and lookup candidates. Set to 0 to bypass persistent lookup caching.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
};
