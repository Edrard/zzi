<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\ZabbixTicket;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyLinkedTicketSyncService;
use App\Services\Znuny\ZnunyTicketSnapshotNormalizer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncLinkedZnunyTicketsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);
    }

    public function test_it_syncs_existing_linked_tickets_and_updates_snapshot()
    {
        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now(),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_state_name' => 'new',
            'znuny_ticket_snapshot_hash' => 'old_hash',
        ]);

        Http::fake([
            '*example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            '*example.invalid/api/ZnunyAgentListTicket/100*' => Http::response([
                'Found' => 1,
                'Ticket' => [
                    'TicketID' => 100,
                    'TicketNumber' => '1000',
                    'State' => 'open',
                    'StateType' => 'open',
                ],
            ], 200),
        ]);

        $this->artisan('znuny:sync-linked-tickets')
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned')
            ->expectsOutputToContain('1') // 1 synced
            ->expectsOutputToContain('Missing');

        $ticket->refresh();
        $this->assertEquals('open', $ticket->znuny_state_name);
        $this->assertNotNull($ticket->znuny_ticket_last_synced_at);
        $this->assertNull($ticket->znuny_ticket_sync_error);
        $this->assertNotEquals('old_hash', $ticket->znuny_ticket_snapshot_hash);
    }

    public function test_it_keeps_previous_data_and_stores_error_when_missing()
    {
        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now(),
            'znuny_ticket_id' => 200,
            'znuny_ticket_number' => '2000',
            'creation_source' => 'manual',
            'znuny_state_name' => 'open',
        ]);

        Http::fake([
            '*example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            '*example.invalid/api/ZnunyAgentListTicket/200*' => Http::response([
                'Error' => [
                    'ErrorCode' => 'Ticket.AccessDenied',
                    'ErrorMessage' => 'Ticket does not exist',
                ],
            ], 200),
        ]);

        $this->artisan('znuny:sync-linked-tickets')
            ->assertSuccessful();

        $ticket->refresh();
        $this->assertEquals('open', $ticket->znuny_state_name); // unchanged
        $this->assertEquals('API Error: Znuny API Error: [Ticket.AccessDenied] Ticket does not exist', $ticket->znuny_ticket_sync_error);
    }

    public function test_it_respects_batch_size()
    {
        for ($i = 0; $i < 3; $i++) {
            ZabbixTicket::create([
                'zabbix_event_id' => 'evt'.$i,
                'zabbix_trigger_id' => 'trg'.$i,
                'zabbix_host_id' => 'host'.$i,
                'zabbix_host_name' => 'Host '.$i,
                'zabbix_problem_name' => 'Problem '.$i,
                'zabbix_severity' => 4,
                'zabbix_started_at' => now(),
                'znuny_ticket_id' => 300 + $i,
                'znuny_ticket_number' => '300'.$i,
            ]);
        }

        Setting::updateOrCreate(['key' => 'znuny_linked_ticket_sync_batch_size'], ['value' => '2']);

        Http::fake([
            '*example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            '*example.invalid/api/ZnunyAgentListTicket*' => Http::response([
                'Found' => 1,
                'Ticket' => [
                    'TicketID' => 300,
                    'TicketNumber' => '3000',
                ],
            ], 200),
        ]);

        $this->artisan('znuny:sync-linked-tickets')
            ->assertSuccessful();

        $checkedCount = ZabbixTicket::whereNotNull('znuny_ticket_last_checked_at')->count();
        $this->assertEquals(2, $checkedCount);
    }

    public function test_audit_logs_meaningful_events()
    {
        Setting::updateOrCreate(['key' => 'znuny_detailed_sync_audit_enabled'], ['value' => 'true', 'type' => 'boolean']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now(),
            'znuny_ticket_id' => 400,
            'znuny_ticket_number' => '4000',
        ]);

        Http::fake([
            '*example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            '*example.invalid/api/ZnunyAgentListTicket/400*' => Http::response([
                'Found' => 1,
                'Ticket' => [
                    'TicketID' => 400,
                    'TicketNumber' => '4000',
                    'State' => 'closed',
                ],
            ], 200),
        ]);

        $this->artisan('znuny:sync-linked-tickets');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'znuny_ticket_sync_updated',
        ]);
    }

    public function test_default_sync_remains_hash_optimized_and_does_not_repair_drift()
    {
        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt5',
            'zabbix_trigger_id' => 'trg5',
            'zabbix_host_id' => 'host5',
            'zabbix_host_name' => 'Host 5',
            'zabbix_problem_name' => 'Problem 5',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now(),
            'znuny_ticket_id' => 500,
            'znuny_ticket_number' => '5000',
            'creation_source' => 'manual',
            'znuny_state_name' => 'drifted state', // local drift
            'znuny_ticket_state_type' => 'open',
            'znuny_ticket_snapshot_hash' => 'hash_for_open', // this hash matches what normalizer will generate for 'open'
        ]);

        // We mock normalizer so we control the hash perfectly
        $mockNormalizer = \Mockery::mock(ZnunyTicketSnapshotNormalizer::class)->makePartial();
        $mockNormalizer->shouldReceive('normalize')->andReturn([
            'znuny_state_name' => 'open',
            'znuny_ticket_state_type' => 'open',
        ]);
        $mockNormalizer->shouldReceive('hash')->andReturn('hash_for_open');
        $this->app->instance(ZnunyTicketSnapshotNormalizer::class, $mockNormalizer);

        Http::fake([
            '*example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            '*example.invalid/api/ZnunyAgentListTicket/500*' => Http::response([
                'Found' => 1,
                'Ticket' => [
                    'TicketID' => 500,
                    'TicketNumber' => '5000',
                    'State' => 'open',
                    'StateType' => 'open',
                ],
            ], 200),
        ]);

        // Default artisan command has reconcile=false
        $this->artisan('znuny:sync-linked-tickets')
            ->assertSuccessful()
            ->expectsOutputToContain('Unchanged'); // Treated as unchanged because hash matches

        $ticket->refresh();
        $this->assertEquals('drifted state', $ticket->znuny_state_name); // Still drifted
    }

    public function test_reconcile_mode_repairs_drift_when_hash_matches()
    {
        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt6',
            'zabbix_trigger_id' => 'trg6',
            'zabbix_host_id' => 'host6',
            'zabbix_host_name' => 'Host 6',
            'zabbix_problem_name' => 'Problem 6',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now(),
            'znuny_ticket_id' => 600,
            'znuny_ticket_number' => '6000',
            'creation_source' => 'manual',
            'znuny_state_name' => 'drifted state', // local drift
            'znuny_ticket_state_type' => 'open',
            'znuny_ticket_snapshot_hash' => 'hash_for_open', // this hash matches what normalizer will generate
        ]);

        $mockNormalizer = \Mockery::mock(ZnunyTicketSnapshotNormalizer::class)->makePartial();
        $mockNormalizer->shouldReceive('normalize')->andReturn([
            'znuny_state_name' => 'open',
            'znuny_ticket_state_type' => 'open',
        ]);
        $mockNormalizer->shouldReceive('hash')->andReturn('hash_for_open');
        $this->app->instance(ZnunyTicketSnapshotNormalizer::class, $mockNormalizer);

        Http::fake([
            '*example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            '*example.invalid/api/ZnunyAgentListTicket/600*' => Http::response([
                'Found' => 1,
                'Ticket' => [
                    'TicketID' => 600,
                    'TicketNumber' => '6000',
                    'State' => 'open',
                    'StateType' => 'open',
                ],
            ], 200),
        ]);

        // Call service with reconcile = true
        $service = app(ZnunyLinkedTicketSyncService::class);
        $result = $service->sync(0, null, true);

        $this->assertEquals(1, $result['reconciled']);
        $this->assertEquals(0, $result['unchanged']);

        $ticket->refresh();
        $this->assertEquals('open', $ticket->znuny_state_name); // Repaired!
    }

    public function test_scheduler_registers_sync_command_with_default_interval()
    {
        $schedule = app(Schedule::class);

        $events = collect($schedule->events())->filter(function ($event) {
            return str_contains($event->command, 'znuny:sync-linked-tickets');
        });

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertEquals('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_scheduler_respects_custom_interval_setting()
    {
        Setting::updateOrCreate(['key' => 'znuny_linked_ticket_sync_interval_minutes'], ['value' => '15', 'type' => 'integer']);

        // Re-resolve console routes
        require base_path('routes/console.php');

        $schedule = app(Schedule::class);
        $events = collect($schedule->events())->filter(function ($event) {
            return str_contains($event->command, 'znuny:sync-linked-tickets');
        });

        // The console.php adds to the Schedule singleton, so we'll just check the last one
        $event = $events->last();
        $this->assertEquals('*/15 * * * *', $event->expression);
    }

    public function test_scheduler_disables_sync_when_interval_is_zero()
    {
        Setting::updateOrCreate(['key' => 'znuny_linked_ticket_sync_interval_minutes'], ['value' => '0', 'type' => 'integer']);

        // We must clear the schedule to test it cleanly
        $this->app->instance(Schedule::class, new Schedule);
        require base_path('routes/console.php');

        $schedule = app(Schedule::class);
        $events = collect($schedule->events())->filter(function ($event) {
            return str_contains($event->command, 'znuny:sync-linked-tickets');
        });

        $this->assertCount(0, $events);
    }

    public function test_scheduled_sync_no_summary_audit_when_disabled()
    {
        Setting::updateOrCreate(['key' => 'znuny_detailed_sync_audit_enabled'], ['value' => 'false', 'type' => 'boolean']);

        $mockSyncService = \Mockery::mock(ZnunyLinkedTicketSyncService::class);
        $mockSyncService->shouldReceive('sync')->andReturn([
            'scanned' => 0, 'synced' => 0, 'unchanged' => 0, 'missing' => 0, 'failed' => 0,
        ]);
        $this->app->instance(ZnunyLinkedTicketSyncService::class, $mockSyncService);

        $this->artisan('znuny:sync-linked-tickets')->run();

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'znuny.linked_tickets_sync.completed',
        ]);
    }

    public function test_scheduled_sync_summary_audit_when_enabled()
    {
        Setting::updateOrCreate(['key' => 'znuny_detailed_sync_audit_enabled'], ['value' => 'true', 'type' => 'boolean']);

        $mockSyncService = \Mockery::mock(ZnunyLinkedTicketSyncService::class);
        $mockSyncService->shouldReceive('sync')->andReturn([
            'scanned' => 0, 'synced' => 0, 'unchanged' => 0, 'missing' => 0, 'failed' => 0,
        ]);
        $this->app->instance(ZnunyLinkedTicketSyncService::class, $mockSyncService);

        $this->artisan('znuny:sync-linked-tickets')->run();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'znuny.linked_tickets_sync.completed',
        ]);
        $log = AuditLog::where('action', 'znuny.linked_tickets_sync.completed')->latest()->first();
        $this->assertEquals('scheduled', $log->context['source']);
        $this->assertTrue($log->context['scheduled']);
        $this->assertFalse($log->context['manual']);
    }

    public function test_manual_sync_summary_audit_when_disabled()
    {
        Setting::updateOrCreate(['key' => 'znuny_detailed_sync_audit_enabled'], ['value' => 'false', 'type' => 'boolean']);

        $mockSyncService = \Mockery::mock(ZnunyLinkedTicketSyncService::class);
        $mockSyncService->shouldReceive('sync')->andReturn([
            'scanned' => 0, 'synced' => 0, 'unchanged' => 0, 'missing' => 0, 'failed' => 0,
        ]);
        $this->app->instance(ZnunyLinkedTicketSyncService::class, $mockSyncService);

        $this->artisan('znuny:sync-linked-tickets', ['--manual' => true])->run();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'znuny.linked_tickets_sync.completed',
        ]);
        $log = AuditLog::where('action', 'znuny.linked_tickets_sync.completed')->latest()->first();
        $this->assertEquals('manual', $log->context['source']);
        $this->assertFalse($log->context['scheduled']);
        $this->assertTrue($log->context['manual']);
    }

    public function test_scheduled_sync_exception_no_audit_when_disabled()
    {
        Setting::updateOrCreate(['key' => 'znuny_detailed_sync_audit_enabled'], ['value' => 'false', 'type' => 'boolean']);

        $mockSyncService = \Mockery::mock(ZnunyLinkedTicketSyncService::class);
        $mockSyncService->shouldReceive('sync')->andThrow(new \Exception('Test Error'));
        $this->app->instance(ZnunyLinkedTicketSyncService::class, $mockSyncService);

        $this->artisan('znuny:sync-linked-tickets')->assertFailed();

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'znuny.linked_tickets_sync.failed',
        ]);
    }

    public function test_scheduled_sync_exception_audit_when_enabled()
    {
        Setting::updateOrCreate(['key' => 'znuny_detailed_sync_audit_enabled'], ['value' => 'true', 'type' => 'boolean']);

        $mockSyncService = \Mockery::mock(ZnunyLinkedTicketSyncService::class);
        $mockSyncService->shouldReceive('sync')->andThrow(new \Exception('Test Error'));
        $this->app->instance(ZnunyLinkedTicketSyncService::class, $mockSyncService);

        $this->artisan('znuny:sync-linked-tickets')->assertFailed();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'znuny.linked_tickets_sync.failed',
        ]);
        $log = AuditLog::where('action', 'znuny.linked_tickets_sync.failed')->latest()->first();
        $this->assertEquals('scheduled', $log->context['source']);
    }

    public function test_manual_sync_exception_audit_when_disabled()
    {
        Setting::updateOrCreate(['key' => 'znuny_detailed_sync_audit_enabled'], ['value' => 'false', 'type' => 'boolean']);

        $mockSyncService = \Mockery::mock(ZnunyLinkedTicketSyncService::class);
        $mockSyncService->shouldReceive('sync')->andThrow(new \Exception('Test Error'));
        $this->app->instance(ZnunyLinkedTicketSyncService::class, $mockSyncService);

        $this->artisan('znuny:sync-linked-tickets', ['--manual' => true])->assertFailed();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'znuny.linked_tickets_sync.failed',
        ]);
        $log = AuditLog::where('action', 'znuny.linked_tickets_sync.failed')->latest()->first();
        $this->assertEquals('manual', $log->context['source']);
    }

    public function test_stats_failed_greater_than_zero_creates_failed_action()
    {
        Setting::updateOrCreate(['key' => 'znuny_detailed_sync_audit_enabled'], ['value' => 'false', 'type' => 'boolean']);

        $mockSyncService = \Mockery::mock(ZnunyLinkedTicketSyncService::class);
        $mockSyncService->shouldReceive('sync')->andReturn([
            'scanned' => 1, 'synced' => 0, 'unchanged' => 0, 'missing' => 0, 'failed' => 1,
        ]);
        $this->app->instance(ZnunyLinkedTicketSyncService::class, $mockSyncService);

        $this->artisan('znuny:sync-linked-tickets', ['--manual' => true])->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'znuny.linked_tickets_sync.failed',
        ]);
    }
}
