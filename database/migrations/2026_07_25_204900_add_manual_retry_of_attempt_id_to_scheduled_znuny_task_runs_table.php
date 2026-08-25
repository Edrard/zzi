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
        Schema::table('scheduled_znuny_task_runs', function (Blueprint $table) {
            $table->foreignId('manual_retry_of_attempt_id')
                ->nullable()
                ->constrained('znuny_ticket_creation_attempts')
                ->restrictOnDelete();

            $table->unique(
                'manual_retry_of_attempt_id',
                'szt_runs_manual_retry_attempt_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_znuny_task_runs', function (Blueprint $table) {
            $table->dropUnique('szt_runs_manual_retry_attempt_unique');
            $table->dropForeign(['manual_retry_of_attempt_id']);
            $table->dropColumn('manual_retry_of_attempt_id');
        });
    }
};
