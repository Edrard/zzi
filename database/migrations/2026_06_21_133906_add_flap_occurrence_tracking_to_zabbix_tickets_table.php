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
            $table->string('zabbix_last_counted_flap_event_id')->nullable()->after('manual_flapping_detected_at');
            $table->timestamp('zabbix_last_counted_flap_started_at')->nullable()->after('zabbix_last_counted_flap_event_id');
            $table->timestamp('manual_last_flap_counted_at')->nullable()->after('zabbix_last_counted_flap_started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zabbix_tickets', function (Blueprint $table) {
            $table->dropColumn([
                'zabbix_last_counted_flap_event_id',
                'zabbix_last_counted_flap_started_at',
                'manual_last_flap_counted_at',
            ]);
        });
    }
};
