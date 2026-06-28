<?php

namespace Tests\Feature\Console;

use App\Services\Znuny\ClosedTicketSyncService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SyncClosedTicketCacheCommandTest extends TestCase
{
    public function test_it_runs_auto_mode()
    {
        $mock = $this->mock(ClosedTicketSyncService::class);
        $mock->shouldReceive('syncAuto')->once()->andReturn([
            'mode' => 'auto',
            'effective_mode' => 'small',
            'reason' => 'scheduled',
            'fetched_count' => 0,
            'cached_count' => 0,
            'duration_ms' => 10,
            'metadata_status' => 'complete',
        ]);

        $this->artisan('znuny:sync-closed-ticket-cache')
            ->expectsOutput('Starting auto closed ticket sync...')
            ->assertSuccessful();
    }

    public function test_it_runs_manual_mode()
    {
        $mock = $this->mock(ClosedTicketSyncService::class);
        $mock->shouldReceive('syncManual')->once()->andReturn([
            'mode' => 'manual',
            'effective_mode' => 'small',
            'reason' => 'manual',
            'fetched_count' => 0,
            'cached_count' => 0,
            'duration_ms' => 10,
            'metadata_status' => 'complete',
        ]);

        $this->artisan('znuny:sync-closed-ticket-cache', ['--manual' => true])
            ->expectsOutput('Starting manual small closed ticket sync...')
            ->assertSuccessful();
    }

    public function test_it_runs_full_mode()
    {
        $mock = $this->mock(ClosedTicketSyncService::class);
        $mock->shouldReceive('syncFull')->with('full', 'forced_full')->once()->andReturn([
            'mode' => 'full',
            'effective_mode' => 'full',
            'reason' => 'forced_full',
            'fetched_count' => 0,
            'cached_count' => 0,
            'duration_ms' => 10,
            'metadata_status' => 'complete',
        ]);

        $this->artisan('znuny:sync-closed-ticket-cache', ['--full' => true])
            ->expectsOutput('Starting forced full closed ticket sync...')
            ->assertSuccessful();
    }

    public function test_it_prevents_manual_and_full_together()
    {
        $this->artisan('znuny:sync-closed-ticket-cache', ['--manual' => true, '--full' => true])
            ->expectsOutput('Cannot use --manual and --full together.')
            ->assertFailed();
    }

    public function test_it_reports_skipped_gracefully()
    {
        $mock = $this->mock(ClosedTicketSyncService::class);
        $mock->shouldReceive('syncAuto')->once()->andReturn([
            'mode' => 'auto',
            'effective_mode' => 'skipped',
            'reason' => 'locked',
        ]);

        $this->artisan('znuny:sync-closed-ticket-cache')
            ->expectsOutput('Sync skipped: locked')
            ->assertSuccessful();
    }

    public function test_it_reports_interval_not_due_gracefully()
    {
        $mock = $this->mock(ClosedTicketSyncService::class);
        $mock->shouldReceive('syncAuto')->once()->andReturn([
            'mode' => 'auto',
            'effective_mode' => 'skipped',
            'reason' => 'interval_not_due',
            'window_days' => 30,
            'lookback_minutes' => 10,
            'fetched_count' => 0,
            'cached_count' => 0,
        ]);

        $this->artisan('znuny:sync-closed-ticket-cache')
            ->expectsOutput('Starting auto closed ticket sync...')
            ->expectsOutput('Sync skipped: interval_not_due')
            ->assertSuccessful();
    }

    public function test_it_reports_errors()
    {
        $mock = $this->mock(ClosedTicketSyncService::class);
        $mock->shouldReceive('syncAuto')->once()->andReturn([
            'mode' => 'auto',
            'effective_mode' => 'small',
            'reason' => 'scheduled',
            'fetched_count' => 0,
            'cached_count' => 0,
            'duration_ms' => 10,
            'metadata_status' => 'incomplete',
            'error_message' => 'API failed',
        ]);

        $this->artisan('znuny:sync-closed-ticket-cache')
            ->expectsOutput('Completed with errors: API failed')
            ->assertFailed();
    }

    public function test_it_runs_purge_mode()
    {
        $mock = $this->mock(ClosedTicketSyncService::class);
        $mock->shouldNotReceive('syncAuto');
        $mock->shouldNotReceive('syncManual');
        $mock->shouldNotReceive('syncFull');

        // Seed some data in Redis
        Redis::set('znuny:closed_ticket:ticket:123', 'foo');
        Redis::set('znuny:closed_ticket:index:2026-06-01', 'bar');
        Redis::set('znuny:closed_ticket:sync:metadata', 'baz');
        Redis::set('znuny:ticket:123', 'active'); // Should not be deleted

        $this->artisan('znuny:sync-closed-ticket-cache', ['--purge' => true])
            ->expectsOutput('Purging closed-ticket cache...')
            ->assertSuccessful();

        $this->assertNull(Redis::get('znuny:closed_ticket:ticket:123'));
        $this->assertNull(Redis::get('znuny:closed_ticket:index:2026-06-01'));
        $this->assertNull(Redis::get('znuny:closed_ticket:sync:metadata'));
        $this->assertEquals('active', Redis::get('znuny:ticket:123'));

        Redis::del('znuny:ticket:123');
    }
}
