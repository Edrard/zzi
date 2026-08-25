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
        Schema::create('znuny_ticket_creation_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('source_type');
            $table->string('source_id');
            $table->string('marker');
            $table->text('subject_original');
            $table->text('subject_sent');
            $table->string('status');
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->string('ticket_number')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('check_attempts')->default(0);
            $table->string('error_summary')->nullable();
            $table->longText('error_details')->nullable();
            $table->json('payload_snapshot')->nullable();
            $table->json('response_snapshot')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['source_type', 'source_id']);
            $table->index('marker');
            $table->index('status');
            $table->index('ticket_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('znuny_ticket_creation_attempts');
    }
};
