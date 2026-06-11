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
        Schema::create('zabbix_tickets', function (Blueprint $table) {
            $table->id();

            $table->string('zabbix_event_id')->unique();
            $table->string('zabbix_trigger_id')->nullable()->index();
            $table->string('zabbix_host_id')->nullable()->index();
            $table->string('zabbix_host_name');
            $table->text('zabbix_problem_name');
            $table->unsignedTinyInteger('zabbix_severity')->nullable();
            $table->timestamp('zabbix_started_at')->nullable();

            $table->unsignedBigInteger('znuny_ticket_id')->index();
            $table->string('znuny_ticket_number')->index();
            $table->unsignedBigInteger('znuny_queue_id')->nullable();
            $table->string('znuny_queue_name')->nullable();
            $table->unsignedBigInteger('znuny_owner_id')->nullable();
            $table->string('znuny_owner_name')->nullable();
            $table->unsignedInteger('znuny_state_id')->nullable();
            $table->string('znuny_state_name')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zabbix_tickets');
    }
};
