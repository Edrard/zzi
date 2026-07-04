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
        Schema::create('znuny_owner_suggestion_stats', function (Blueprint $table) {
            $table->id();
            $table->string('normalized_problem_key')->index();
            $table->string('queue_name')->nullable()->index();
            $table->string('owner_id')->nullable()->index();
            $table->string('owner_login')->nullable()->index();
            $table->decimal('score', 10, 4)->default(0);
            $table->unsignedInteger('sample_count')->default(0);
            $table->unsignedInteger('recent_count')->default(0);
            $table->unsignedInteger('old_count')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['normalized_problem_key', 'queue_name', 'owner_id'], 'zos_stats_unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('znuny_owner_suggestion_stats');
    }
};
