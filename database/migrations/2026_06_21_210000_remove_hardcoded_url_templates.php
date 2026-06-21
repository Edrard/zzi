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
        DB::table('settings')
            ->where('key', 'zabbix_problem_url_template')
            ->where('value', 'https://zabbix.vamark.com/zabbix.php?show=1&action=problem.view&triggerids%5B%5D={trigger_id}')
            ->update(['value' => '']);

        DB::table('settings')
            ->where('key', 'znuny_ticket_url_template')
            ->where('value', 'https://otrs.vamark.net/otrs/index.pl?Action=AgentTicketZoom;TicketID={ticket_id}')
            ->update(['value' => '']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No down needed
    }
};
