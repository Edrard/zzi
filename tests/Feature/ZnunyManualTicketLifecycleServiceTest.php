<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\ZabbixProblem;
use App\Models\ZabbixTicket;
use App\Services\Zabbix\ZabbixProblemCache;
use App\Services\Znuny\ZnunyManualTicketLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ZnunyManualTicketLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ZabbixProblemCache::class)->clear();
        Redis::set('zabbix:problems:last_poll', json_encode([
            'status' => 'success',
            'polled_at' => now()->toIso8601String(),
        ]));

        Setting::updateOrCreate(['key' => 'manual_ticket_auto_close_schedule_mode'], ['value' => 'execute', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'default_close_delay_hours'], ['value' => '4', 'type' => 'integer']);
        Setting::updateOrCreate(['key' => 'manual_ticket_flap_threshold'], ['value' => '3', 'type' => 'integer']);
        Setting::updateOrCreate(['key' => 'manual_ticket_extra_flapping_delay_hours'], ['value' => '24', 'type' => 'integer']);
    }

    public function test_active_manual_linked_ticket_remains_active()
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
            'znuny_ticket_state_type' => 'open',
        ]);

        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => 'evt1',
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
            ],
        ], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $stats = $service->evaluate();

        $ticket->refresh();

        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_ACTIVE, $ticket->manual_lifecycle_status);
        $this->assertTrue($ticket->zabbix_problem_is_active);
        $this->assertNull($ticket->manual_close_eligible_at);
        $this->assertEquals(1, $stats['active']);
    }

    public function test_resolved_manual_linked_ticket_calculates_close_eligible_at()
    {
        Carbon::setTestNow(now());

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDay(),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
        ]);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $stats = $service->evaluate();

        $ticket->refresh();

        $this->assertFalse($ticket->zabbix_problem_is_active);
        $this->assertEquals(now()->toDateTimeString(), $ticket->zabbix_problem_resolved_at->toDateTimeString());
        $this->assertEquals(now()->addHours(4)->toDateTimeString(), $ticket->manual_close_eligible_at->toDateTimeString());
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_RESOLVED_WAITING, $ticket->manual_lifecycle_status);
    }

    public function test_resolved_but_still_waiting()
    {
        Carbon::setTestNow(now());

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDay(),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => false,
            'zabbix_problem_resolved_at' => now()->subHours(2), // Needs 4 hours
        ]);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $stats = $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_RESOLVED_WAITING, $ticket->manual_lifecycle_status);
        $this->assertEquals(1, $stats['resolved_waiting']);
    }

    public function test_close_candidate()
    {
        Carbon::setTestNow(now());

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => false,
            'zabbix_problem_resolved_at' => now()->subHours(5), // Needs 4 hours, so it is ready
        ]);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $stats = $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE, $ticket->manual_lifecycle_status);
        $this->assertEquals(1, $stats['close_candidate']);
    }

    public function test_active_ticket_evaluated_multiple_times_remains_active_with_zero_flap_count()
    {
        Carbon::setTestNow(now());

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => true,
            'manual_lifecycle_status' => 'active',
            'manual_flap_count' => 0,
        ]);

        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => 'evt2',
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
            ],
        ], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(0, $ticket->manual_flap_count);
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_ACTIVE, $ticket->manual_lifecycle_status);

        // Evaluate again
        $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(0, $ticket->manual_flap_count);
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_ACTIVE, $ticket->manual_lifecycle_status);
    }

    public function test_flapping_ticket_evaluated_multiple_times_remains_flapping_without_increment()
    {
        Carbon::setTestNow(now());
        Setting::updateOrCreate(['key' => 'manual_ticket_flap_threshold'], ['value' => '3', 'type' => 'integer']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => true,
            'manual_lifecycle_status' => 'flapping',
            'manual_flap_count' => 5, // High flap count
            'manual_flapping_detected_at' => now()->subHours(2),
        ]);

        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => 'evt2',
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
            ],
        ], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(5, $ticket->manual_flap_count);
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_FLAPPING, $ticket->manual_lifecycle_status);

        // Evaluate again
        $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(5, $ticket->manual_flap_count);
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_FLAPPING, $ticket->manual_lifecycle_status);
    }

    public function test_resolved_waiting_to_active_increments_exactly_once()
    {
        Carbon::setTestNow(now());
        Setting::updateOrCreate(['key' => 'manual_ticket_flap_threshold'], ['value' => '3', 'type' => 'integer']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => false,
            'manual_lifecycle_status' => 'resolved_waiting',
            'manual_flap_count' => 0,
            'zabbix_problem_resolved_at' => now()->subHours(1),
        ]);
        $ticket->created_at = now()->subDays(2);
        $ticket->saveQuietly();

        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => 'evt2',
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
                'clock' => now()->subMinutes(30)->timestamp,
            ],
        ], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(1, $ticket->manual_flap_count);
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_ACTIVE, $ticket->manual_lifecycle_status);
        $this->assertEquals('evt2', $ticket->zabbix_last_counted_flap_event_id);
        $this->assertNotNull($ticket->manual_last_flap_counted_at);

        // Evaluate again
        $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(1, $ticket->manual_flap_count);
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_ACTIVE, $ticket->manual_lifecycle_status);
    }

    public function test_same_occurrence_return_does_not_increment_flap_count()
    {
        Carbon::setTestNow(now());
        Setting::updateOrCreate(['key' => 'manual_ticket_flap_threshold'], ['value' => '3', 'type' => 'integer']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => false,
            'manual_lifecycle_status' => 'resolved_waiting',
            'manual_flap_count' => 0,
            'zabbix_problem_resolved_at' => now()->subHours(1),
        ]);
        $ticket->created_at = now()->subDays(2);
        $ticket->saveQuietly();

        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => 'evt1', // Same occurrence!
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
                'started_at' => now()->subDays(2)->toIso8601String(),
            ],
        ], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(0, $ticket->manual_flap_count);
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_ACTIVE, $ticket->manual_lifecycle_status);
        $this->assertTrue($ticket->zabbix_problem_is_active);
        $this->assertNull($ticket->zabbix_last_counted_flap_event_id);
        $this->assertNull($ticket->zabbix_last_counted_flap_started_at);
        $this->assertNull($ticket->manual_last_flap_counted_at);
    }

    public function test_close_candidate_to_active_increments_exactly_once()
    {
        Carbon::setTestNow(now());
        Setting::updateOrCreate(['key' => 'manual_ticket_flap_threshold'], ['value' => '3', 'type' => 'integer']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => false,
            'manual_lifecycle_status' => 'close_candidate',
            'manual_flap_count' => 0,
            'zabbix_problem_resolved_at' => now()->subHours(5),
            'manual_close_eligible_at' => now()->subHours(1),
        ]);
        $ticket->created_at = now()->subDays(2);
        $ticket->saveQuietly();

        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => 'evt2',
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
                'clock' => now()->subMinutes(30)->timestamp,
            ],
        ], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(1, $ticket->manual_flap_count);
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_ACTIVE, $ticket->manual_lifecycle_status);
    }

    public function test_flapping_disabled_when_threshold_is_zero()
    {
        Carbon::setTestNow(now());
        Setting::updateOrCreate(['key' => 'manual_ticket_flap_threshold'], ['value' => '0', 'type' => 'integer']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => false,
            'manual_lifecycle_status' => 'resolved_waiting', // Added missing status
            'zabbix_problem_resolved_at' => now()->subHours(1),
            'manual_flap_count' => 5, // high count
        ]);
        $ticket->created_at = now()->subDays(2);
        $ticket->saveQuietly();

        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => 'evt2',
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
                'clock' => now()->subMinutes(30)->timestamp,
            ],
        ], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $stats = $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(6, $ticket->manual_flap_count); // count still increments
        $this->assertNull($ticket->manual_flapping_detected_at); // but not detected
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_ACTIVE, $ticket->manual_lifecycle_status);
        $this->assertEquals(1, $stats['active']);
    }

    public function test_polluted_flapping_active_ticket_is_demoted_to_active()
    {
        Carbon::setTestNow(now());
        Setting::updateOrCreate(['key' => 'manual_ticket_flap_threshold'], ['value' => '3', 'type' => 'integer']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => true,
            'manual_lifecycle_status' => 'flapping',
            'manual_flap_count' => 1, // Polluted data: not reaching threshold but status is flapping
            'manual_flapping_detected_at' => now()->subDay(),
            'zabbix_problem_resolved_at' => now()->subHours(1), // Polluted data: has resolved_at but is active
        ]);

        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => 'evt2',
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
            ],
        ], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $stats = $service->evaluate();

        $ticket->refresh();
        // Since it was active -> active, flap_count remains 1
        $this->assertEquals(1, $ticket->manual_flap_count);
        // It self-heals by clearing detected_at
        $this->assertNull($ticket->manual_flapping_detected_at);
        // And demoting status to active
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_ACTIVE, $ticket->manual_lifecycle_status);
        // And clears the polluted resolved_at
        $this->assertNull($ticket->zabbix_problem_resolved_at);
        $this->assertEquals(1, $stats['active']);
    }

    public function test_transition_brings_flap_count_to_threshold()
    {
        Carbon::setTestNow(now());
        Setting::updateOrCreate(['key' => 'manual_ticket_flap_threshold'], ['value' => '3', 'type' => 'integer']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => false,
            'manual_lifecycle_status' => 'resolved_waiting',
            'manual_flap_count' => 2,
            'zabbix_problem_resolved_at' => now()->subHours(1),
        ]);
        $ticket->created_at = now()->subDays(2);
        $ticket->saveQuietly();

        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => 'evt2',
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
                'clock' => now()->subMinutes(30)->timestamp,
            ],
        ], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(3, $ticket->manual_flap_count);
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_FLAPPING, $ticket->manual_lifecycle_status);
    }

    public function test_repair_command_dry_run()
    {
        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => true,
            'manual_lifecycle_status' => 'flapping',
            'manual_flap_count' => 5,
        ]);

        $this->artisan('znuny:repair-flapping-tickets --dry-run')
            ->expectsOutputToContain('Would repair Ticket ID '.$ticket->id)
            ->expectsOutputToContain('Dry run complete.')
            ->assertSuccessful();

        $ticket->refresh();
        $this->assertEquals(5, $ticket->manual_flap_count);
        $this->assertEquals('flapping', $ticket->manual_lifecycle_status);
    }

    public function test_repair_command_execute()
    {
        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => true,
            'manual_lifecycle_status' => 'flapping',
            'manual_flap_count' => 5,
            'manual_flapping_detected_at' => now()->subHour(),
        ]);

        $this->artisan('znuny:repair-flapping-tickets')
            ->expectsOutputToContain('Repairing polluted flapping tickets...')
            ->expectsOutputToContain('Repairing Ticket ID '.$ticket->id)
            ->expectsOutputToContain('Repaired 1 tickets.')
            ->assertSuccessful();

        $ticket->refresh();
        $this->assertEquals(0, $ticket->manual_flap_count);
        $this->assertEquals('active', $ticket->manual_lifecycle_status);
        $this->assertNull($ticket->manual_flapping_detected_at);
        $this->assertTrue($ticket->zabbix_problem_is_active);
    }

    public function test_extra_flapping_delay()
    {
        Carbon::setTestNow(now());
        Setting::updateOrCreate(['key' => 'manual_ticket_flap_threshold'], ['value' => '2', 'type' => 'integer']);
        Setting::updateOrCreate(['key' => 'manual_ticket_extra_flapping_delay_hours'], ['value' => '24', 'type' => 'integer']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => true,
            'manual_flap_count' => 2,
            'manual_flapping_detected_at' => now()->subDay(),
        ]);

        // We do NOT create a ZabbixProblem, so it gets resolved now
        $service = app(ZnunyManualTicketLifecycleService::class);
        $service->evaluate();

        $ticket->refresh();
        $this->assertFalse($ticket->zabbix_problem_is_active);
        $this->assertNotNull($ticket->zabbix_problem_resolved_at);
        // Delay is 4 (default) + 24 (flap delay) = 28 hours
        $this->assertEquals(now()->addHours(28)->toDateTimeString(), $ticket->manual_close_eligible_at->toDateTimeString());
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_RESOLVED_WAITING, $ticket->manual_lifecycle_status);
    }

    public function test_closed_znuny_ticket()
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
            'znuny_ticket_state_type' => 'closed', // CLOSED!
        ]);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $stats = $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CLOSED, $ticket->manual_lifecycle_status);
        $this->assertEquals(1, $stats['closed']);
    }

    public function test_only_manual_tickets_are_evaluated()
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
            'creation_source' => 'auto', // AUTO!
            'znuny_ticket_state_type' => 'open',
        ]);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $stats = $service->evaluate();

        $this->assertEquals(0, $stats['scanned']);
        $ticket->refresh();
        $this->assertNull($ticket->manual_lifecycle_status);
    }

    public function test_command_output()
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
            'znuny_ticket_state_type' => 'closed',
        ]);

        $this->artisan('znuny:evaluate-manual-ticket-lifecycle')
            ->assertSuccessful()
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Scanned', '1'],
                    ['Active', '0'],
                    ['Resolved Waiting', '0'],
                    ['Close Candidate', '0'],
                    ['Flapping', '0'],
                    ['Closed', '1'],
                    ['Skipped', '0'],
                    ['Failed', '0'],
                ]
            );
    }

    public function test_cache_stale()
    {
        Carbon::setTestNow(now());

        // Make cache stale
        Redis::set('zabbix:problems:last_poll', json_encode([
            'status' => 'success',
            'polled_at' => now()->subMinutes(10)->toIso8601String(),
        ]));

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDay(),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
        ]);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $stats = $service->evaluate();

        $ticket->refresh();

        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CACHE_STALE, $ticket->manual_lifecycle_status);
        $this->assertNull($ticket->zabbix_problem_resolved_at);
        $this->assertNull($ticket->manual_close_eligible_at);
        $this->assertEquals(1, $stats['cache_stale']);
    }

    public function test_cache_metadata_missing()
    {
        Carbon::setTestNow(now());

        // Make cache metadata missing
        Redis::del('zabbix:problems:last_poll');

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDay(),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
        ]);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $stats = $service->evaluate();

        $ticket->refresh();

        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CACHE_STALE, $ticket->manual_lifecycle_status);
        $this->assertNull($ticket->zabbix_problem_resolved_at);
        $this->assertNull($ticket->manual_close_eligible_at);
        $this->assertEquals(1, $stats['cache_stale']);
    }

    public function test_stale_cache_does_not_increment_flap_counter()
    {
        Carbon::setTestNow(now());

        // Make cache stale
        Redis::set('zabbix:problems:last_poll', json_encode([
            'status' => 'success',
            'polled_at' => now()->subMinutes(10)->toIso8601String(),
        ]));

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => false,
            'zabbix_problem_resolved_at' => now()->subHours(1),
            'manual_flap_count' => 0,
        ]);

        // Even if active again, cache is stale, so it shouldn't evaluate
        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => 'evt2',
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
            ],
        ], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $service->evaluate();

        $ticket->refresh();

        $this->assertEquals(0, $ticket->manual_flap_count); // Unchanged
        $this->assertFalse($ticket->zabbix_problem_is_active);
        $this->assertEquals(now()->subHours(1)->toDateTimeString(), $ticket->zabbix_problem_resolved_at->toDateTimeString());
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CACHE_STALE, $ticket->manual_lifecycle_status);
    }

    public function test_missing_host_id_sets_identity_missing()
    {
        Carbon::setTestNow(now());

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => null,
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'manual_lifecycle_status' => 'active',
            'zabbix_problem_is_active' => true,
        ]);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $stats = $service->evaluate();

        $ticket->refresh();

        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_IDENTITY_MISSING, $ticket->manual_lifecycle_status);
        $this->assertNull($ticket->zabbix_problem_is_active);
        $this->assertNull($ticket->zabbix_problem_resolved_at);
        $this->assertNull($ticket->manual_close_eligible_at);
        $this->assertEquals(1, $stats['identity_missing']);
    }

    public function test_individual_cache_gap_prevents_false_resolved()
    {
        Carbon::setTestNow(now());

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDay(),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => true,
            'manual_lifecycle_status' => 'active',
        ]);

        $cache = app(ZabbixProblemCache::class);
        // Put a DIFFERENT problem in the main index (simulating missing from latest poll)
        $cache->putMany([
            [
                'eventid' => 'evt2',
                'objectid' => 'trg2',
                'hosts' => [['hostid' => 'host2']],
            ],
        ], 60);

        // BUT the individual problem key is still alive in Redis
        Redis::setex('zabbix:problem:evt1', 60, json_encode([
            'eventid' => 'evt1',
            'objectid' => 'trg1',
            'hosts' => [['hostid' => 'host1']],
        ]));

        $service = app(ZnunyManualTicketLifecycleService::class);
        $service->evaluate();

        $ticket->refresh();

        // Should remain active because it was found in individual cache!
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_ACTIVE, $ticket->manual_lifecycle_status);
        $this->assertTrue($ticket->zabbix_problem_is_active);
    }

    public function test_missing_trigger_id_sets_identity_missing()
    {
        Carbon::setTestNow(now());

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => null,
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'manual_lifecycle_status' => 'close_candidate',
            'zabbix_problem_resolved_at' => now()->subDays(1),
            'manual_close_eligible_at' => now()->subHours(1),
        ]);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $stats = $service->evaluate();

        $ticket->refresh();

        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_IDENTITY_MISSING, $ticket->manual_lifecycle_status);
        $this->assertNull($ticket->zabbix_problem_is_active);
        $this->assertNull($ticket->zabbix_problem_resolved_at);
        $this->assertNull($ticket->manual_close_eligible_at);
        $this->assertEquals(1, $stats['identity_missing']);
    }

    public function test_missing_both_identity_sets_identity_missing()
    {
        Carbon::setTestNow(now());

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => null,
            'zabbix_host_id' => null,
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'manual_lifecycle_status' => 'resolved_waiting',
            'zabbix_problem_resolved_at' => now()->subHours(1),
        ]);
        $ticket->created_at = now()->subDays(2);
        $ticket->saveQuietly();

        $service = app(ZnunyManualTicketLifecycleService::class);
        $stats = $service->evaluate();

        $ticket->refresh();

        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_IDENTITY_MISSING, $ticket->manual_lifecycle_status);
        $this->assertNull($ticket->zabbix_problem_is_active);
        $this->assertNull($ticket->zabbix_problem_resolved_at);
        $this->assertNull($ticket->manual_close_eligible_at);
        $this->assertEquals(1, $stats['identity_missing']);
    }

    public function test_duplicate_evaluation_of_already_counted_occurrence_does_not_increment_again()
    {
        Carbon::setTestNow(now());
        Setting::updateOrCreate(['key' => 'manual_ticket_flap_threshold'], ['value' => '3', 'type' => 'integer']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => true,
            'manual_lifecycle_status' => 'active',
            'manual_flap_count' => 1,
            'zabbix_last_counted_flap_event_id' => 'evt2',
            'zabbix_last_counted_flap_started_at' => now()->subHours(5),
            'manual_last_flap_counted_at' => now()->subHours(2),
        ]);

        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => 'evt2',
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
                'started_at' => now()->subHours(5)->toIso8601String(), // Same started_at as last counted
            ],
        ], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(1, $ticket->manual_flap_count); // No increment
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_ACTIVE, $ticket->manual_lifecycle_status);
        $this->assertEquals('evt2', $ticket->zabbix_last_counted_flap_event_id);
    }

    public function test_different_eventid_without_reliable_started_at_does_not_increment_flap_count()
    {
        Carbon::setTestNow(now());
        Setting::updateOrCreate(['key' => 'manual_ticket_flap_threshold'], ['value' => '3', 'type' => 'integer']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => false,
            'manual_lifecycle_status' => 'resolved_waiting',
            'manual_flap_count' => 0,
            'zabbix_problem_resolved_at' => now()->subHours(1),
        ]);
        $ticket->created_at = now()->subDays(2);
        $ticket->saveQuietly();

        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => 'evt_different',
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
                // No clock, no started_at
            ],
        ], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(0, $ticket->manual_flap_count);
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_ACTIVE, $ticket->manual_lifecycle_status);
        $this->assertNull($ticket->zabbix_last_counted_flap_event_id);
        $this->assertNull($ticket->manual_last_flap_counted_at);
    }

    public function test_last_counted_flap_started_at_guard_prevents_duplicate_or_older_occurrence()
    {
        Carbon::setTestNow(now());
        Setting::updateOrCreate(['key' => 'manual_ticket_flap_threshold'], ['value' => '3', 'type' => 'integer']);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => false,
            'manual_lifecycle_status' => 'resolved_waiting',
            'manual_flap_count' => 1,
            'zabbix_problem_resolved_at' => now()->subHours(1),
            'zabbix_last_counted_flap_event_id' => 'evt_prev',
            'zabbix_last_counted_flap_started_at' => now()->subHours(5),
        ]);

        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => 'evt_new_but_older',
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
                // Older than last counted flap started_at
                'started_at' => now()->subHours(6)->toIso8601String(),
            ],
        ], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(1, $ticket->manual_flap_count); // Does not increment
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_ACTIVE, $ticket->manual_lifecycle_status);
        $this->assertEquals('evt_prev', $ticket->zabbix_last_counted_flap_event_id);
    }

    public function test_cache_gap_with_same_occurrence_does_not_count_as_flap()
    {
        Carbon::setTestNow(now());
        Setting::updateOrCreate(['key' => 'manual_ticket_flap_threshold'], ['value' => '3', 'type' => 'integer']);

        // Ticket previously active, then false-resolved
        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'zabbix_problem_is_active' => false,
            'manual_lifecycle_status' => 'resolved_waiting',
            'manual_flap_count' => 0,
        ]);
        $ticket->created_at = now()->subDays(2);
        $ticket->saveQuietly();

        // Cache again contains the same original eventid and started_at
        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => 'evt1',
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
                'started_at' => now()->subDays(2)->toIso8601String(),
            ],
        ], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(0, $ticket->manual_flap_count);
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_ACTIVE, $ticket->manual_lifecycle_status);
        $this->assertNull($ticket->zabbix_last_counted_flap_event_id);
    }

    public function test_reopen_candidate_inside_window()
    {
        Carbon::setTestNow(now());

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'closed',
            'manual_lifecycle_closed_at' => now()->subHours(2),
        ]);

        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => 'evt2',
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
                'started_at' => now()->subHour()->toIso8601String(),
            ],
        ], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_REOPEN_CANDIDATE, $ticket->manual_lifecycle_status);
        $this->assertTrue($ticket->zabbix_problem_is_active);
    }

    public function test_reopen_candidate_outside_window()
    {
        Carbon::setTestNow(now());

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(10),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'closed',
            'manual_lifecycle_closed_at' => now()->subDays(2),
        ]);

        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => 'evt2',
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
                'started_at' => now()->subHour()->toIso8601String(),
            ],
        ], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CLOSED, $ticket->manual_lifecycle_status);
        $this->assertFalse($ticket->zabbix_problem_is_active);
    }

    public function test_reopen_candidate_stale_cache()
    {
        Carbon::setTestNow(now());

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'closed',
            'manual_lifecycle_closed_at' => now()->subHours(2),
        ]);

        Redis::set('zabbix:problems:last_poll', json_encode([
            'status' => 'success',
            'polled_at' => now()->subHours(5)->toIso8601String(),
        ]));

        $service = app(ZnunyManualTicketLifecycleService::class);
        $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CLOSED, $ticket->manual_lifecycle_status);
    }

    public function test_reopen_candidate_no_active_problem()
    {
        Carbon::setTestNow(now());

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'closed',
            'manual_lifecycle_closed_at' => now()->subHours(2),
        ]);

        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $service->evaluate();

        $ticket->refresh();
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CLOSED, $ticket->manual_lifecycle_status);
    }

    public function test_reopen_candidate_repeated_evaluations()
    {
        Carbon::setTestNow(now());

        $closedAt = now()->subHours(2);

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'closed',
            'manual_lifecycle_closed_at' => $closedAt,
            'manual_flap_count' => 0,
        ]);

        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => 'evt2',
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
                'started_at' => now()->subHour()->toIso8601String(),
            ],
        ], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        
        $service->evaluate();
        $ticket->refresh();
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_REOPEN_CANDIDATE, $ticket->manual_lifecycle_status);
        $this->assertEquals($closedAt->toDateTimeString(), $ticket->manual_lifecycle_closed_at->toDateTimeString());
        $this->assertEquals(0, $ticket->manual_flap_count);

        Carbon::setTestNow(now()->addMinutes(5));
        Redis::set('zabbix:problems:last_poll', json_encode([
            'status' => 'success',
            'polled_at' => now()->toIso8601String(),
        ]));

        $service->evaluate();
        $ticket->refresh();
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_REOPEN_CANDIDATE, $ticket->manual_lifecycle_status);
        $this->assertEquals($closedAt->toDateTimeString(), $ticket->manual_lifecycle_closed_at->toDateTimeString());
        $this->assertEquals(0, $ticket->manual_flap_count);
    }

    public function test_non_manual_ticket_is_not_reopen_candidate()
    {
        Carbon::setTestNow(now());

        $ticket = ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now()->subDays(2),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'auto',
            'znuny_ticket_state_type' => 'closed',
            'manual_lifecycle_closed_at' => now()->subHours(2),
        ]);

        $cache = app(ZabbixProblemCache::class);
        $cache->putMany([
            [
                'eventid' => 'evt1',
                'objectid' => 'trg1',
                'hosts' => [['hostid' => 'host1']],
                'started_at' => now()->subDays(2)->toIso8601String(),
            ],
        ], 60);

        $service = app(ZnunyManualTicketLifecycleService::class);
        $stats = $service->evaluate();

        $ticket->refresh();
        $this->assertNotEquals(ZnunyManualTicketLifecycleService::STATUS_REOPEN_CANDIDATE, $ticket->manual_lifecycle_status);
        $this->assertEquals(0, $stats['reopen_candidate']);
    }
}
