<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SETTINGS = [
        [
            'key' => 'znuny_default_agent_id',
            'value' => '',
            'type' => 'string',
            'description' => 'Authoritative UserID for the default agent used for automatic ticket creation.',
        ],
        [
            'key' => 'znuny_default_agent_login',
            'value' => '',
            'type' => 'string',
            'description' => 'Snapshot of the UserLogin for the default agent used for automatic ticket creation.',
        ],
        [
            'key' => 'znuny_default_agent_name',
            'value' => '',
            'type' => 'string',
            'description' => 'Snapshot of the UserFullname for the default agent used for automatic ticket creation.',
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::SETTINGS as $setting) {
            DB::table('settings')->insertOrIgnore($setting);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $keys = array_column(self::SETTINGS, 'key');
        DB::table('settings')->whereIn('key', $keys)->delete();
    }
};
