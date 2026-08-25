<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('zabbix_tickets', function (Blueprint $table) {
            $table->string('creation_source')->default('manual')->after('zabbix_started_at');
            $table->string('znuny_ticket_state_type')->nullable()->after('znuny_state_name');
            $table->string('znuny_priority')->nullable()->after('znuny_ticket_state_type');
            $table->unsignedBigInteger('znuny_priority_id')->nullable()->after('znuny_priority');
            $table->dateTime('znuny_ticket_changed_at')->nullable()->after('znuny_priority_id');
            $table->dateTime('znuny_ticket_last_checked_at')->nullable()->after('znuny_ticket_changed_at');
            $table->dateTime('znuny_ticket_last_synced_at')->nullable()->after('znuny_ticket_last_checked_at');
            $table->text('znuny_ticket_sync_error')->nullable()->after('znuny_ticket_last_synced_at');
            $table->string('znuny_ticket_snapshot_hash')->nullable()->after('znuny_ticket_sync_error');
        });

        DB::table('zabbix_tickets')->update(['creation_source' => 'manual']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zabbix_tickets', function (Blueprint $table) {
            $table->dropColumn([
                'creation_source',
                'znuny_ticket_state_type',
                'znuny_priority',
                'znuny_priority_id',
                'znuny_ticket_changed_at',
                'znuny_ticket_last_checked_at',
                'znuny_ticket_last_synced_at',
                'znuny_ticket_sync_error',
                'znuny_ticket_snapshot_hash',
            ]);
        });
    }
};
