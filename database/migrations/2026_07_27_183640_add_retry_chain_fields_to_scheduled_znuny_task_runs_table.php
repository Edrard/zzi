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
            $table->unsignedBigInteger('root_run_id')->nullable()->index();
            $table->unsignedBigInteger('parent_run_id')->nullable()->unique();
            $table->unsignedInteger('retry_sequence')->default(0);
            $table->dateTime('resolved_at')->nullable();
            $table->string('resolution_type')->nullable();

            $table->foreign('root_run_id')->references('id')->on('scheduled_znuny_task_runs')->restrictOnDelete();
            $table->foreign('parent_run_id')->references('id')->on('scheduled_znuny_task_runs')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_znuny_task_runs', function (Blueprint $table) {
            $table->dropForeign(['parent_run_id']);
            $table->dropForeign(['root_run_id']);

            $table->dropUnique(['parent_run_id']);
            $table->dropIndex(['root_run_id']);

            $table->dropColumn([
                'resolution_type',
                'resolved_at',
                'retry_sequence',
                'parent_run_id',
                'root_run_id',
            ]);
        });
    }
};
