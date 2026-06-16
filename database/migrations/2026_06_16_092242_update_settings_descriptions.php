<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('settings')->where('key', 'cleanup_enabled')->update([
            'description' => 'Enables scheduled housekeeping for old local records such as logs, statistics, resolved problem history, closed local ticket links, and failed job records according to the retention settings. Does not delete active Zabbix problems or Znuny tickets.',
        ]);

        DB::table('settings')->where('key', 'cleanup_batch_size')->update([
            'description' => 'Maximum number of records to delete per cleanup pass for each cleanup category. Lower values reduce load; higher values clean old data faster.',
        ]);

        DB::table('settings')->where('key', 'znuny_queue_host_mappings')->update([
            'description' => 'Maps primary Zabbix host prefixes to Znuny queues. Used only when the primary queue candidate is not found in Znuny.',
        ]);
    }

    public function down(): void
    {
        // Reversible where practical but exact original descriptions are hard to reconstruct without knowing them.
        // Best effort:
        DB::table('settings')->where('key', 'cleanup_enabled')->update([
            'description' => 'Enable periodic cleanup of old system records (action logs, resolved problems, closed tickets).',
        ]);
        DB::table('settings')->where('key', 'cleanup_batch_size')->update([
            'description' => 'Number of records to delete per cleanup chunk.',
        ]);
        DB::table('settings')->where('key', 'znuny_queue_host_mappings')->update([
            'description' => 'Mapping of primary Zabbix host prefixes to Znuny queues. Used as a fallback when the primary queue candidate is not found.',
        ]);
    }
};
