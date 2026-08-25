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
        Schema::create('znuny_owner_suggestion_observations', function (Blueprint $table) {
            $table->id();
            $table->string('problem_name');
            $table->string('normalized_problem_key')->index();
            $table->string('queue_name')->nullable()->index();
            $table->string('owner_id')->nullable()->index();
            $table->string('owner_login')->nullable()->index();
            $table->string('zabbix_event_id')->nullable()->index();
            $table->string('zabbix_host_name')->nullable();
            $table->string('customer_user_login')->nullable();
            $table->string('znuny_ticket_id')->nullable()->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('znuny_owner_suggestion_observations');
    }
};
