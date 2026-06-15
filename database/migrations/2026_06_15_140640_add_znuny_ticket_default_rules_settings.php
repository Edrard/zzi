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
            [
                'key' => 'znuny_queue_from_host_regex',
                'value' => '^(?<queue>[^\s]+)',
                'type' => 'string',
                'description' => 'Regular expression used to detect the default Znuny Queue from the Zabbix host name. It must contain the named capture group (?<queue>...). Default takes the first word of the host name. Example: "TestCompany swiss test01" → "TestCompany".',
            ],
            [
                'key' => 'znuny_customer_user_from_queue_template',
                'value' => '<queue>Clients',
                'type' => 'string',
                'description' => 'Template used to generate the default Znuny CustomerUser login from the detected Queue. Use <queue> as placeholder. Default: <queue>Clients. Example: Queue "TestCompany" → "TestCompanyClients".',
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'znuny_queue_from_host_regex',
            'znuny_customer_user_from_queue_template',
        ])->delete();
    }
};
