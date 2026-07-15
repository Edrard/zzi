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
        Setting::firstOrCreate(
            ['key' => 'znuny_lookup_cache_ttl_minutes'],
            [
                'value' => '60',
                'type' => 'integer',
                'description' => 'Lifetime in minutes for reusable Znuny lookup data such as queue owners, CustomerUsers, states, priorities, types, filtered queues, and lookup candidates. Set to 0 to bypass persistent lookup caching.',
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally preserve the setting and any administrator-modified value.
        // This is a data/default migration and rollback must not remove a pre-existing
        // or subsequently modified application setting.
    }
};
