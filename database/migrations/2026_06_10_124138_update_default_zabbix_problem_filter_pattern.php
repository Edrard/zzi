<?php

use App\Models\ZabbixProblemFilter;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        ZabbixProblemFilter::updateOrCreate(
            ['name' => 'Ignore Zabbix proxy missing data noise'],
            [
                'enabled' => true,
                'field' => 'name',
                'match_type' => 'regex',
                'pattern' => '/^(Zabbix proxy:\s*)?More than \d+ items having missing data for more than \d+ minutes$/',
                'case_sensitive' => false,
                'description' => 'Ignore noisy Zabbix proxy missing data aggregate problems.',
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        ZabbixProblemFilter::where('name', 'Ignore Zabbix proxy missing data noise')
            ->update([
                'pattern' => '/^Zabbix proxy: More than \d+ items having missing data for more than \d+ minutes$/',
            ]);
    }
};
