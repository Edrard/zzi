<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // OwnerID was previously saved as a string into owner_login by the Filament UI.
        DB::table('scheduled_znuny_tasks')
            ->whereNull('owner_id')
            ->whereNotNull('owner_login')
            ->orderBy('id')
            ->chunkById(100, function ($tasks) {
                foreach ($tasks as $task) {
                    if (is_numeric($task->owner_login) && $task->owner_login > 0) {
                        DB::table('scheduled_znuny_tasks')
                            ->where('id', $task->id)
                            ->update(['owner_id' => (int) $task->owner_login]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot cleanly reverse this, data loss is acceptable for down
    }
};
