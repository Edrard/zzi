<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\Znuny\ClosedTicketCacheService;
use App\Services\Znuny\ClosedTicketSyncService;
use App\Services\Znuny\ZnunyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ClosedTicketSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private ClosedTicketCacheService $cacheServiceMock;

    private ZnunyClient $znunyClientMock;

    private ClosedTicketSyncService $syncService;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::updateOrCreate(['key' => 'znuny_closed_ticket_window_days'], ['value' => '30']);
        Setting::updateOrCreate(['key' => 'znuny_closed_ticket_small_sync_interval_minutes'], ['value' => '5']);
        Setting::updateOrCreate(['key' => 'znuny_closed_ticket_sync_audit_auto_enabled'], ['value' => 'false']);

        $this->cacheServiceMock = \Mockery::mock(ClosedTicketCacheService::class);
        $this->znunyClientMock = \Mockery::mock(ZnunyClient::class);

        $this->syncService = new ClosedTicketSyncService($this->cacheServiceMock, $this->znunyClientMock);
    }

    public function test_sync_auto_with_good_metadata_runs_small_sync()
    {
        $this->cacheServiceMock->shouldReceive('validateMetadata')->with(30)->andReturn([
            'is_valid' => true,
            'reason' => 'complete',
            'metadata_status' => 'complete',
        ]);
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn([
            'last_small_completed_at' => now()->subMinutes(6)->toDateTimeString(),
        ]);

        $this->znunyClientMock->shouldReceive('searchTickets')->once()->andReturn([]);
        $this->cacheServiceMock->shouldReceive('setMetadata')->once();

        $result = $this->syncService->syncAuto();

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('small', $result['effective_mode']);
        $this->assertEquals('scheduled', $result['reason']);
        $this->assertEquals(10, $result['lookback_minutes']); // max(2*5, 6+1) = 10
    }

    public function test_sync_auto_with_recent_small_sync_skips_interval()
    {
        $this->cacheServiceMock->shouldReceive('validateMetadata')->with(30)->andReturn([
            'is_valid' => true,
            'reason' => 'complete',
            'metadata_status' => 'complete',
        ]);
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn([
            'last_small_completed_at' => now()->subMinutes(2)->toDateTimeString(),
        ]);

        $this->znunyClientMock->shouldNotReceive('searchTickets');
        $this->cacheServiceMock->shouldNotReceive('setMetadata');

        $result = $this->syncService->syncAuto();

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('skipped', $result['effective_mode']);
        $this->assertEquals('interval_not_due', $result['reason']);
        $this->assertEquals(0, $result['fetched_count']);
        $this->assertEquals(0, $result['cached_count']);
    }

    public function test_sync_auto_with_bad_metadata_escalates_to_full_sync_even_if_recent()
    {
        $this->cacheServiceMock->shouldReceive('validateMetadata')->with(30)->andReturn([
            'is_valid' => false,
            'reason' => 'metadata_missing',
            'metadata_status' => 'incomplete',
        ]);
        // Even if interval is strictly "not due", invalid metadata forces full sync
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn([
            'last_small_completed_at' => now()->subMinutes(1)->toDateTimeString(),
        ]);

        $this->znunyClientMock->shouldReceive('searchTickets')->once()->andReturn([]);
        $this->cacheServiceMock->shouldReceive('setMetadata')->once();

        $result = $this->syncService->syncAuto();

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('full', $result['effective_mode']);
        $this->assertEquals('metadata_missing', $result['reason']);
    }

    public function test_sync_manual_always_runs_small_sync()
    {
        // Even if validateMetadata would return false, manual skips validation.
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn(null);

        $this->znunyClientMock->shouldReceive('searchTickets')->once()->andReturn([]);
        $this->cacheServiceMock->shouldReceive('setMetadata')->once();

        $result = $this->syncService->syncManual();

        $this->assertEquals('manual', $result['mode']);
        $this->assertEquals('small', $result['effective_mode']);
        $this->assertEquals('manual', $result['reason']);
    }

    public function test_sync_full_runs_full_sync()
    {
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn(null);

        $ticket = [
            'TicketID' => 123,
            'Created' => now()->subDays(29)->toDateTimeString(), // Older than boundary
        ];

        $this->znunyClientMock->shouldReceive('searchTickets')->once()->andReturn([$ticket]);
        $this->cacheServiceMock->shouldReceive('upsertTicket')->once();

        $this->cacheServiceMock->shouldReceive('setMetadata')->once()->withArgs(function ($args) {
            return $args['oldest_loaded_closed_at'] <= now()->subDays(30)->toDateTimeString();
        });

        $result = $this->syncService->syncFull();

        $this->assertEquals('full', $result['mode']);
        $this->assertEquals('full', $result['effective_mode']);
        $this->assertEquals('forced_full', $result['reason']);
    }

    public function test_sync_full_with_zero_tickets_sets_boundary()
    {
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn(null);

        $this->znunyClientMock->shouldReceive('searchTickets')->once()->andReturn([]);

        $this->cacheServiceMock->shouldReceive('setMetadata')->once()->withArgs(function ($args) {
            $boundary = now()->subDays(30)->timestamp;
            $oldest = strtotime($args['oldest_loaded_closed_at']);

            return abs($boundary - $oldest) <= 5 && ! isset($args['newest_loaded_closed_at']);
        });

        $result = $this->syncService->syncFull();
        $this->assertEquals('full', $result['effective_mode']);
    }

    public function test_sync_full_with_partial_final_page_sets_boundary()
    {
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn(null);

        $ticket = [
            'TicketID' => 123,
            'Created' => now()->subDays(2)->toDateTimeString(), // Newer than boundary
        ];

        // Returns 1 ticket, which is < limit (100), meaning pagination is exhausted.
        $this->znunyClientMock->shouldReceive('searchTickets')->once()->andReturn([$ticket]);
        $this->cacheServiceMock->shouldReceive('upsertTicket')->once();

        $this->cacheServiceMock->shouldReceive('setMetadata')->once()->withArgs(function ($args) {
            $boundary = now()->subDays(30)->timestamp;
            $oldest = strtotime($args['oldest_loaded_closed_at']);

            return abs($boundary - $oldest) <= 5;
        });

        $result = $this->syncService->syncFull();
        $this->assertEquals('full', $result['effective_mode']);
    }

    public function test_sync_locked_skips()
    {
        Cache::put('znuny:closed_ticket:sync:lock', true, 10);

        $result = $this->syncService->syncAuto();

        $this->assertEquals('skipped', $result['effective_mode']);
        $this->assertEquals('locked', $result['reason']);
    }

    public function test_settings_are_clamped_to_minimums()
    {
        Setting::updateOrCreate(['key' => 'znuny_closed_ticket_window_days'], ['value' => '0']);
        Setting::updateOrCreate(['key' => 'znuny_closed_ticket_small_sync_interval_minutes'], ['value' => '-5']);

        $settings = $this->syncService->getSettings();

        $this->assertEquals(30, $settings['window_days']);
        $this->assertEquals(5, $settings['small_sync_interval']);
    }

    public function test_manual_sync_always_audits_even_when_auto_audit_disabled()
    {
        Setting::updateOrCreate(['key' => 'znuny_closed_ticket_sync_audit_auto_enabled'], ['value' => 'false']);

        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn(null);
        $this->znunyClientMock->shouldReceive('searchTickets')->once()->andReturn([]);
        $this->cacheServiceMock->shouldReceive('setMetadata')->once();

        $initialCount = AuditLog::count();
        $this->syncService->syncManual();

        $this->assertEquals($initialCount + 1, AuditLog::count());
        $log = AuditLog::latest('id')->first();
        $this->assertEquals('znuny.closed_ticket.sync', $log->action);
        $this->assertEquals('manual', $log->context['mode']);
    }

    public function test_auto_small_sync_does_not_audit_when_auto_audit_disabled()
    {
        Setting::updateOrCreate(['key' => 'znuny_closed_ticket_sync_audit_auto_enabled'], ['value' => 'false']);

        $this->cacheServiceMock->shouldReceive('validateMetadata')->with(30)->andReturn([
            'is_valid' => true,
            'reason' => 'complete',
            'metadata_status' => 'complete',
        ]);
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn([
            'last_small_completed_at' => now()->subMinutes(6)->toDateTimeString(),
        ]);
        $this->znunyClientMock->shouldReceive('searchTickets')->once()->andReturn([]);
        $this->cacheServiceMock->shouldReceive('setMetadata')->once();

        $initialCount = AuditLog::count();
        $this->syncService->syncAuto();

        $this->assertEquals($initialCount, AuditLog::count());
    }

    public function test_auto_small_sync_audits_when_auto_audit_enabled()
    {
        Setting::updateOrCreate(['key' => 'znuny_closed_ticket_sync_audit_auto_enabled'], ['value' => 'true']);

        $this->cacheServiceMock->shouldReceive('validateMetadata')->with(30)->andReturn([
            'is_valid' => true,
            'reason' => 'complete',
            'metadata_status' => 'complete',
        ]);
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn([
            'last_small_completed_at' => now()->subMinutes(6)->toDateTimeString(),
        ]);
        $this->znunyClientMock->shouldReceive('searchTickets')->once()->andReturn([]);
        $this->cacheServiceMock->shouldReceive('setMetadata')->once();

        $initialCount = AuditLog::count();
        $this->syncService->syncAuto();

        $this->assertEquals($initialCount + 1, AuditLog::count());
        $log = AuditLog::latest('id')->first();
        $this->assertEquals('znuny.closed_ticket.sync', $log->action);
        $this->assertEquals('auto', $log->context['mode']);
    }

    public function test_forced_full_sync_does_not_audit_when_auto_audit_disabled()
    {
        Setting::updateOrCreate(['key' => 'znuny_closed_ticket_sync_audit_auto_enabled'], ['value' => 'false']);

        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn(null);
        $this->znunyClientMock->shouldReceive('searchTickets')->once()->andReturn([]);
        $this->cacheServiceMock->shouldReceive('setMetadata')->once();

        $initialCount = AuditLog::count();
        $this->syncService->syncFull();

        $this->assertEquals($initialCount, AuditLog::count());
    }

    public function test_forced_full_sync_audits_when_auto_audit_enabled()
    {
        Setting::updateOrCreate(['key' => 'znuny_closed_ticket_sync_audit_auto_enabled'], ['value' => 'true']);

        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn(null);
        $this->znunyClientMock->shouldReceive('searchTickets')->once()->andReturn([]);
        $this->cacheServiceMock->shouldReceive('setMetadata')->once();

        $initialCount = AuditLog::count();
        $this->syncService->syncFull();

        $this->assertEquals($initialCount + 1, AuditLog::count());
        $log = AuditLog::latest('id')->first();
        $this->assertEquals('znuny.closed_ticket.sync', $log->action);
        $this->assertEquals('full', $log->context['mode']);
    }

    public function test_failed_manual_sync_still_audits()
    {
        Setting::updateOrCreate(['key' => 'znuny_closed_ticket_sync_audit_auto_enabled'], ['value' => 'false']);

        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn(null);
        $this->znunyClientMock->shouldReceive('searchTickets')->once()->andThrow(new \Exception('Znuny API failed'));
        $this->cacheServiceMock->shouldReceive('setMetadata')->once();

        $initialCount = AuditLog::count();
        $result = $this->syncService->syncManual();

        $this->assertEquals('Znuny API failed', $result['error_message']);
        $this->assertEquals($initialCount + 1, AuditLog::count());
        $log = AuditLog::latest('id')->first();
        $this->assertEquals('znuny.closed_ticket.sync', $log->action);
        $this->assertEquals('manual', $log->context['mode']);
        $this->assertEquals('Znuny API failed', $log->context['error_message']);
    }

    public function test_failed_auto_sync_audits_only_when_auto_audit_enabled()
    {
        // Test with disabled first
        Setting::updateOrCreate(['key' => 'znuny_closed_ticket_sync_audit_auto_enabled'], ['value' => 'false']);

        $this->cacheServiceMock->shouldReceive('validateMetadata')->with(30)->andReturn([
            'is_valid' => true,
            'reason' => 'complete',
            'metadata_status' => 'complete',
        ]);
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn([
            'last_small_completed_at' => now()->subMinutes(6)->toDateTimeString(),
        ]);
        $this->znunyClientMock->shouldReceive('searchTickets')->once()->andThrow(new \Exception('Znuny API failed'));
        $this->cacheServiceMock->shouldReceive('setMetadata')->once();

        $initialCount = AuditLog::count();
        $this->syncService->syncAuto();
        $this->assertEquals($initialCount, AuditLog::count());

        // Test with enabled
        Setting::updateOrCreate(['key' => 'znuny_closed_ticket_sync_audit_auto_enabled'], ['value' => 'true']);

        $this->cacheServiceMock->shouldReceive('validateMetadata')->with(30)->andReturn([
            'is_valid' => true,
            'reason' => 'complete',
            'metadata_status' => 'complete',
        ]);
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn([
            'last_small_completed_at' => now()->subMinutes(6)->toDateTimeString(),
        ]);
        $this->znunyClientMock->shouldReceive('searchTickets')->once()->andThrow(new \Exception('Znuny API failed'));
        $this->cacheServiceMock->shouldReceive('setMetadata')->once();

        $this->syncService->syncAuto();
        $this->assertEquals($initialCount + 1, AuditLog::count());
        $log = AuditLog::latest('id')->first();
        $this->assertEquals('znuny.closed_ticket.sync', $log->action);
        $this->assertEquals('auto', $log->context['mode']);
        $this->assertEquals('Znuny API failed', $log->context['error_message']);
    }

    public function test_full_sync_uses_offset_pagination_not_page()
    {
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn(null);

        // Need 100 tickets to prevent exhaustion break
        $page1 = [];
        for ($i = 1; $i <= 100; $i++) {
            $page1[] = ['TicketID' => $i, 'Created' => now()->subDays(1)->toDateTimeString()];
        }
        $page2 = [];
        for ($i = 101; $i <= 102; $i++) {
            $page2[] = ['TicketID' => $i, 'Created' => now()->subDays(29)->toDateTimeString()];
        }

        $this->znunyClientMock->shouldReceive('searchTickets')
            ->withArgs(function ($args) {
                return $args['Offset'] === 0 && ! isset($args['Page']) && ! isset($args['Queue']) && ! isset($args['QueueID']);
            })
            ->once()
            ->andReturn($page1);

        $this->znunyClientMock->shouldReceive('searchTickets')
            ->withArgs(function ($args) {
                return $args['Offset'] === 100 && ! isset($args['Page']) && ! isset($args['Queue']) && ! isset($args['QueueID']);
            })
            ->once()
            ->andReturn($page2);

        $this->cacheServiceMock->shouldReceive('upsertTicket')->times(102);
        $this->cacheServiceMock->shouldReceive('setMetadata')->once();

        $result = $this->syncService->syncFull();
        $this->assertEquals('full', $result['effective_mode']);
        $this->assertEquals(102, $result['fetched_count']);
    }

    public function test_small_sync_uses_offset_pagination_not_page()
    {
        $this->cacheServiceMock->shouldReceive('validateMetadata')->andReturn(['is_valid' => true]);
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn(['last_small_completed_at' => now()->subMinutes(10)->toDateTimeString()]);

        $this->znunyClientMock->shouldReceive('searchTickets')
            ->withArgs(function ($args) {
                return $args['Offset'] === 0 && ! isset($args['Page']) && ! isset($args['Queue']) && ! isset($args['QueueID']);
            })
            ->once()
            ->andReturn([]);

        $this->cacheServiceMock->shouldReceive('setMetadata')->once();

        $result = $this->syncService->syncAuto();
        $this->assertEquals('small', $result['effective_mode']);
    }

    public function test_full_sync_aborts_on_repeated_page()
    {
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn(null);

        $page1 = [];
        for ($i = 1; $i <= 100; $i++) {
            $page1[] = ['TicketID' => $i, 'Created' => now()->subDays(1)->toDateTimeString()];
        }

        $this->znunyClientMock->shouldReceive('searchTickets')->twice()->andReturn($page1);

        $this->cacheServiceMock->shouldReceive('upsertTicket')->times(100);
        $this->cacheServiceMock->shouldReceive('setMetadata')->once()->withArgs(function ($args) {
            return isset($args['last_error']) && str_contains($args['last_error'], 'Repeated closed-ticket search page detected');
        });

        $result = $this->syncService->syncFull();

        $this->assertStringContainsString('Repeated closed-ticket search page detected', $result['error_message']);
        $this->assertEquals('incomplete', $result['metadata_status']);
    }

    public function test_small_sync_aborts_on_repeated_page()
    {
        $this->cacheServiceMock->shouldReceive('validateMetadata')->andReturn(['is_valid' => true]);
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn(['last_small_completed_at' => now()->subMinutes(10)->toDateTimeString()]);

        $page1 = [];
        for ($i = 1; $i <= 100; $i++) {
            $page1[] = ['TicketID' => $i, 'Changed' => now()->subMinutes(1)->toDateTimeString(), 'Created' => now()->subMinutes(1)->toDateTimeString()];
        }

        $this->znunyClientMock->shouldReceive('searchTickets')->twice()->andReturn($page1);

        $this->cacheServiceMock->shouldReceive('upsertTicket')->times(100);
        $this->cacheServiceMock->shouldReceive('setMetadata')->once()->withArgs(function ($args) {
            return isset($args['last_error']) && str_contains($args['last_error'], 'Repeated closed-ticket search page detected');
        });

        $result = $this->syncService->syncAuto();

        $this->assertStringContainsString('Repeated closed-ticket search page detected', $result['error_message']);
    }

    public function test_full_sync_aborts_on_max_pages()
    {
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn(null);

        $callCount = 0;
        $this->znunyClientMock->shouldReceive('searchTickets')->andReturnUsing(function () use (&$callCount) {
            $callCount++;
            $page = [];
            for ($i = 1; $i <= 100; $i++) {
                $page[] = ['TicketID' => ($callCount * 1000) + $i, 'Created' => now()->toDateTimeString()];
            }

            return $page;
        });

        $this->cacheServiceMock->shouldReceive('upsertTicket')->times(100000);
        $this->cacheServiceMock->shouldReceive('setMetadata')->once()->withArgs(function ($args) {
            return isset($args['last_error']) && str_contains($args['last_error'], 'Max pages limit (1000) exceeded');
        });

        $result = $this->syncService->syncFull();

        $this->assertStringContainsString('Max pages limit (1000) exceeded', $result['error_message']);
    }

    public function test_full_sync_uses_sort_by_created_and_caches_valid_tickets()
    {
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn(null);

        $ticketValid = [
            'TicketID' => 123,
            'Created' => now()->subDays(5)->toDateTimeString(),
        ];
        $ticketOld = [
            'TicketID' => 124,
            'Created' => now()->subDays(35)->toDateTimeString(),
        ];
        $ticketMissingCreated = [
            'TicketID' => 125,
        ];

        $this->znunyClientMock->shouldReceive('searchTickets')
            ->withArgs(function ($args) {
                return $args['SortBy'] === 'Created' && $args['SortDirection'] === 'Down';
            })
            ->once()
            ->andReturn([$ticketValid, $ticketOld, $ticketMissingCreated]);

        // Only valid ticket is upserted
        $this->cacheServiceMock->shouldReceive('upsertTicket')->once()->withArgs(function ($t) {
            return $t['TicketID'] === 123;
        });
        $this->cacheServiceMock->shouldReceive('setMetadata')->once();

        $result = $this->syncService->syncFull();
        $this->assertEquals('full', $result['effective_mode']);
        $this->assertEquals(1, $result['cached_count']);
    }

    public function test_small_sync_skips_old_created_tickets_even_if_changed_is_recent()
    {
        $this->cacheServiceMock->shouldReceive('validateMetadata')->andReturn(['is_valid' => true]);
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn(['last_small_completed_at' => now()->subMinutes(10)->toDateTimeString()]);

        $ticketValid = [
            'TicketID' => 123,
            'Changed' => now()->subMinutes(1)->toDateTimeString(),
            'Created' => now()->subDays(5)->toDateTimeString(),
        ];
        $ticketOldWithRecentChange = [
            'TicketID' => 124,
            'Changed' => now()->subMinutes(1)->toDateTimeString(),
            'Created' => now()->subDays(35)->toDateTimeString(),
        ];

        $this->znunyClientMock->shouldReceive('searchTickets')
            ->withArgs(function ($args) {
                return $args['SortBy'] === 'Changed';
            })
            ->once()
            ->andReturn([$ticketValid, $ticketOldWithRecentChange]);

        $this->cacheServiceMock->shouldReceive('upsertTicket')->once()->withArgs(function ($t) {
            return $t['TicketID'] === 123;
        });
        $this->cacheServiceMock->shouldReceive('setMetadata')->once();

        $result = $this->syncService->syncAuto();
        $this->assertEquals('small', $result['effective_mode']);
        $this->assertEquals(1, $result['cached_count']);
    }

    public function test_duplicate_page_after_boundary_completes_safely()
    {
        $this->cacheServiceMock->shouldReceive('getMetadata')->andReturn(null);

        // A duplicate page that contains tickets without Created, preventing it from breaking naturally,
        // but it is considered past the boundary because it has no valid creates.
        $page1 = [];
        for ($i = 1; $i <= 100; $i++) {
            $page1[] = ['TicketID' => $i];
        }

        $this->znunyClientMock->shouldReceive('searchTickets')->twice()->andReturn($page1);

        // None are upserted because they have no valid creates
        $this->cacheServiceMock->shouldReceive('upsertTicket')->never();

        // Metadata is saved without an error
        $this->cacheServiceMock->shouldReceive('setMetadata')->once()->withArgs(function ($args) {
            return ! isset($args['last_error']) && $args['integrity_status'] === 'complete';
        });

        $result = $this->syncService->syncFull();
        $this->assertEquals('full', $result['effective_mode']);
        $this->assertEquals('complete', $result['metadata_status']);
    }
}
