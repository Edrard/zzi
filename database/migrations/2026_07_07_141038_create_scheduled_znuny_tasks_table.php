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
        Schema::create('scheduled_znuny_tasks', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('name');
            $table->string('cron_expression')->nullable();
            $table->string('timezone')->nullable()->default(config('app.timezone'));
            $table->timestamp('next_run_at')->nullable();

            // Ticket override fields
            $table->unsignedBigInteger('queue_id')->nullable();
            $table->string('queue_name')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('owner_login')->nullable();
            $table->string('customer_user_login')->nullable();
            $table->unsignedBigInteger('type_id')->nullable();
            $table->string('type_name')->nullable();
            $table->unsignedBigInteger('priority_id')->nullable();
            $table->string('priority_name')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->string('state_name')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->string('service_name')->nullable();
            $table->unsignedBigInteger('sla_id')->nullable();
            $table->string('sla_name')->nullable();

            // Ticket content
            $table->string('subject')->nullable();
            $table->longText('body')->nullable();

            // Fast last-run summary fields
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_status')->nullable();
            $table->unsignedBigInteger('last_ticket_id')->nullable();
            $table->string('last_ticket_number')->nullable();
            $table->string('last_error_summary')->nullable();

            // Ownership
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_znuny_tasks');
    }
};
