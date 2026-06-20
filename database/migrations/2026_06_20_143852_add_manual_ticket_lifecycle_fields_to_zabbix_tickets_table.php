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
            $table->string('manual_lifecycle_status')->nullable()->after('znuny_ticket_sync_error');
            $table->boolean('zabbix_problem_is_active')->nullable()->after('manual_lifecycle_status');
            $table->timestamp('zabbix_problem_last_seen_active_at')->nullable()->after('zabbix_problem_is_active');
            $table->timestamp('zabbix_problem_resolved_at')->nullable()->after('zabbix_problem_last_seen_active_at');
            $table->timestamp('manual_close_eligible_at')->nullable()->after('zabbix_problem_resolved_at');
            $table->integer('manual_flap_count')->default(0)->after('manual_close_eligible_at');
            $table->timestamp('manual_flapping_detected_at')->nullable()->after('manual_flap_count');
            $table->timestamp('manual_lifecycle_last_checked_at')->nullable()->after('manual_flapping_detected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zabbix_tickets', function (Blueprint $table) {
            $table->dropColumn([
                'manual_lifecycle_status',
                'zabbix_problem_is_active',
                'zabbix_problem_last_seen_active_at',
                'zabbix_problem_resolved_at',
                'manual_close_eligible_at',
                'manual_flap_count',
                'manual_flapping_detected_at',
                'manual_lifecycle_last_checked_at',
            ]);
        });
    }
};
