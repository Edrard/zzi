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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('track_new_tickets')->default(false)->after('ui_locale');
            $table->timestamp('ticket_tracking_since')->nullable()->after('track_new_tickets');
        });

        Schema::create('znuny_ticket_seen_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('znuny_ticket_id');
            $table->timestamp('opened_at')->useCurrent();

            $table->unique(['user_id', 'znuny_ticket_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('znuny_ticket_seen_statuses');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['track_new_tickets', 'ticket_tracking_since']);
        });
    }
};
