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
        Schema::create('daily_statistics', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('zabbix_problems_seen')->default(0);
            $table->unsignedInteger('tickets_created')->default(0);
            $table->unsignedInteger('tickets_reopened')->default(0);
            $table->unsignedInteger('tickets_auto_closed')->default(0);
            $table->unsignedInteger('tickets_manual_created')->default(0);
            $table->unsignedInteger('pattern_matched')->default(0);
            $table->unsignedInteger('pattern_unmatched')->default(0);
            $table->unsignedInteger('failed_actions')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_statistics');
    }
};
