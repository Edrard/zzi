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
            ['key' => 'zabbix_exclude_suppressed_problems'],
            [
                'key' => 'zabbix_exclude_suppressed_problems',
                'value' => 'true',
                'type' => 'boolean',
                'description' => 'Exclude suppressed Zabbix problems from polling results',
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Setting::where('key', 'zabbix_exclude_suppressed_problems')->delete();
    }
};
