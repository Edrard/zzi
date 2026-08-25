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
            $table->timestamp('manual_reopened_at')->nullable()->after('manual_lifecycle_closed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zabbix_tickets', function (Blueprint $table) {
            $table->dropColumn('manual_reopened_at');
        });
    }
};
