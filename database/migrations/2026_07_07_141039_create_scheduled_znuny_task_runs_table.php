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
        Schema::create('scheduled_znuny_task_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_znuny_task_id')->nullable()->constrained('scheduled_znuny_tasks')->nullOnDelete();

            $table->string('task_name_snapshot');
            $table->string('run_type'); // scheduled, manual, catch_up
            $table->timestamp('scheduled_for');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('status'); // pending, running, success, failed, skipped, duplicate

            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->string('ticket_number')->nullable();

            $table->string('error_summary')->nullable();
            $table->longText('error_details')->nullable();

            $table->json('payload_snapshot')->nullable();
            $table->json('response_snapshot')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Anti-duplicate: scheduled_znuny_task_id + scheduled_for
            // Nullable foreign keys in unique indexes can sometimes cause issues with some DBs allowing multiple nulls,
            // but for a task_id + scheduled_for it behaves as expected in MySQL: multiple null task_ids are allowed.
            $table->unique(['scheduled_znuny_task_id', 'scheduled_for'], 'szt_runs_task_id_scheduled_for_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_znuny_task_runs');
    }
};
