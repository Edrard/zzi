<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('zabbix_tickets', function (Blueprint $table) {
            $table->timestamp('znuny_ticket_closed_at')->nullable()->after('znuny_ticket_last_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zabbix_tickets', function (Blueprint $table) {
            $table->dropColumn('znuny_ticket_closed_at');
        });
    }
};
