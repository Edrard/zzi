<?php

namespace Tests\Feature\Console\Commands;

use App\Services\OwnerSuggestion\OwnerSuggestionStatsRebuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RebuildOwnerSuggestionStatsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_succeeds_and_outputs_summary()
    {
        $mockRebuilder = $this->mock(OwnerSuggestionStatsRebuilder::class);
        $mockRebuilder->shouldReceive('rebuild')->once()->andReturn([
            'observations_scanned' => 10,
            'stats_written' => 2,
            'raw_deleted' => 1,
            'retention_days' => 70,
            'old_weight_coefficient' => 0.5,
            'cleanup_days' => 360,
        ]);

        $this->artisan('owner-suggestion:rebuild-stats')
            ->expectsOutput('Starting Owner Suggestion stats rebuild...')
            ->expectsOutput('Rebuild completed successfully.')
            ->expectsTable(
                ['Metric', 'Value'],
                [
                    ['Observations Scanned', 10],
                    ['Stats Written', 2],
                    ['Raw Observations Deleted', 1],
                    ['Retention Days', 70],
                    ['Old Weight Coefficient', 0.5],
                    ['Cleanup Days', 360],
                ]
            )
            ->assertSuccessful();
    }

    public function test_command_failure_returns_failure_if_rebuilder_throws()
    {
        $mockRebuilder = $this->mock(OwnerSuggestionStatsRebuilder::class);
        $mockRebuilder->shouldReceive('rebuild')->once()->andThrow(new \Exception('DB failure'));

        $this->artisan('owner-suggestion:rebuild-stats')
            ->expectsOutput('Starting Owner Suggestion stats rebuild...')
            ->expectsOutput('Rebuild failed: DB failure')
            ->assertFailed();
    }
}
