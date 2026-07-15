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
            ['key' => 'znuny_ticket_article_cache_ttl_minutes'],
            [
                'value' => '15',
                'type' => 'integer',
                'description' => 'Lifetime in minutes for cached Znuny ticket article data. Set to 0 to bypass persistent ticket article caching.',
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
