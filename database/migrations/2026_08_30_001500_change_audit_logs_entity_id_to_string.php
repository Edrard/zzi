<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * AuditLogger intentionally accepts int|string|null entity IDs.
         * Some auditable entities (for example Znuny CustomerUser Login) are
         * naturally strings, so the database column must support both.
         *
         * Existing numeric IDs are preserved losslessly as strings.
         */
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('entity_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Rollback is only safe before string entity IDs have been written.
         * A rollback after that point may fail rather than silently truncate.
         */
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('entity_id')->nullable()->change();
        });
    }
};
