<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\ZabbixTicket;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyManualTicketCloseCandidateService;
use App\Services\Znuny\ZnunyManualTicketLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZnunyManualTicketCloseCandidateServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'manual_ticket_auto_close_schedule_mode'], ['value' => 'execute', 'type' => 'string']);
    }

    public function test_finds_close_candidate()
    {
        ZabbixTicket::create([
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
            'manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE,
            'manual_close_eligible_at' => now()->subMinute(),
        ]);

        $service = app(ZnunyManualTicketCloseCandidateService::class);
        $report = $service->review();

        $this->assertCount(1, $report['candidates']);
        $this->assertEquals(1, $report['summary']['candidates']);
        $this->assertEquals(1, $report['summary']['scanned']);
    }

    public function test_skips_active_ticket()
    {
        ZabbixTicket::create([
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
            'manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_ACTIVE,
        ]);

        $service = app(ZnunyManualTicketCloseCandidateService::class);
        $report = $service->review();

        $this->assertCount(0, $report['candidates']);
        $this->assertEquals(1, $report['summary']['skipped_not_candidate']);
    }

    public function test_skips_resolved_waiting_ticket()
    {
        ZabbixTicket::create([
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
            'manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_RESOLVED_WAITING,
        ]);

        $service = app(ZnunyManualTicketCloseCandidateService::class);
        $report = $service->review();

        $this->assertCount(0, $report['candidates']);
        $this->assertEquals(1, $report['summary']['skipped_not_candidate']);
    }

    public function test_skips_closed_znuny_ticket()
    {
        ZabbixTicket::create([
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
            'manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE,
            'manual_close_eligible_at' => now()->subMinute(),
        ]);

        $service = app(ZnunyManualTicketCloseCandidateService::class);
        $report = $service->review();

        $this->assertCount(0, $report['candidates']);
        $this->assertEquals(1, $report['summary']['skipped_closed']);
    }

    public function test_skips_non_manual_ticket()
    {
        ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => 'host1',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now(),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'auto',
            'znuny_ticket_state_type' => 'open',
            'manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE,
            'manual_close_eligible_at' => now()->subMinute(),
        ]);

        $service = app(ZnunyManualTicketCloseCandidateService::class);
        $report = $service->review();

        $this->assertCount(0, $report['candidates']);
        $this->assertEquals(1, $report['summary']['skipped_not_manual']);
    }

    public function test_skips_cache_stale()
    {
        ZabbixTicket::create([
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
            'manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_CACHE_STALE,
            'manual_close_eligible_at' => now()->subMinute(),
        ]);

        $service = app(ZnunyManualTicketCloseCandidateService::class);
        $report = $service->review();

        $this->assertCount(0, $report['candidates']);
        $this->assertEquals(1, $report['summary']['skipped_cache_stale']);
    }

    public function test_skips_identity_missing()
    {
        ZabbixTicket::create([
            'zabbix_event_id' => 'evt1',
            'zabbix_trigger_id' => 'trg1',
            'zabbix_host_id' => null,
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now(),
            'znuny_ticket_id' => 100,
            'znuny_ticket_number' => '1000',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_IDENTITY_MISSING,
        ]);

        $service = app(ZnunyManualTicketCloseCandidateService::class);
        $report = $service->review();

        $this->assertCount(0, $report['candidates']);
        $this->assertEquals(1, $report['summary']['skipped_identity_missing']);
    }

    public function test_skips_future_eligibility()
    {
        ZabbixTicket::create([
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
            'manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE,
            'manual_close_eligible_at' => now()->addMinutes(10),
        ]);

        $service = app(ZnunyManualTicketCloseCandidateService::class);
        $report = $service->review();

        $this->assertCount(0, $report['candidates']);
        $this->assertEquals(1, $report['summary']['skipped_future_eligibility']);
    }

    public function test_command_output()
    {
        ZabbixTicket::create([
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
            'manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE,
            'manual_close_eligible_at' => now()->subMinute(),
        ]);

        $this->artisan('znuny:review-manual-ticket-close-candidates')
            ->assertSuccessful()
            ->expectsTable(
                ['Ticket Number', 'Host', 'Problem', 'Znuny State', 'Lifecycle Status', 'Resolved Since', 'Close Eligible At', 'Flap Count', 'Reason'],
                [
                    [
                        '1000',
                        'Host 1',
                        'Problem 1',
                        'open',
                        'close_candidate',
                        '-',
                        now()->subMinute()->toDateTimeString(),
                        '0',
                        'Problem resolved since unknown, close delay elapsed, ticket still open, cache fresh.',
                    ],
                ]
            );
    }

    public function test_json_option()
    {
        ZabbixTicket::create([
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
            'manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE,
            'manual_close_eligible_at' => now()->subMinute(),
        ]);

        $this->artisan('znuny:review-manual-ticket-close-candidates --json')
            ->assertSuccessful();
    }

    public function test_focused_ticket_option()
    {
        ZabbixTicket::create([
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
            'manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE,
            'manual_close_eligible_at' => now()->subMinute(),
        ]);

        $ticket2 = ZabbixTicket::create([
            'zabbix_event_id' => 'evt2',
            'zabbix_trigger_id' => 'trg2',
            'zabbix_host_id' => 'host2',
            'zabbix_host_name' => 'Host 2',
            'zabbix_problem_name' => 'Problem 2',
            'zabbix_severity' => 4,
            'zabbix_started_at' => now(),
            'znuny_ticket_id' => 101,
            'znuny_ticket_number' => '1001',
            'creation_source' => 'manual',
            'znuny_ticket_state_type' => 'open',
            'manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE,
            'manual_close_eligible_at' => now()->subMinute(),
        ]);

        $service = app(ZnunyManualTicketCloseCandidateService::class);
        $report = $service->review($ticket2->id);

        $this->assertCount(1, $report['candidates']);
        $this->assertEquals('1001', $report['candidates'][0]['ticket_number']);
        $this->assertEquals(1, $report['summary']['candidates']);
        $this->assertEquals(1, $report['summary']['scanned']);
    }

    public function test_no_znuny_writes()
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
            'manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE,
            'manual_close_eligible_at' => now()->subMinute(),
        ]);

        $mockClient = \Mockery::mock(ZnunyClient::class);
        $mockClient->shouldNotReceive('post');
        $this->app->instance(ZnunyClient::class, $mockClient);

        $service = app(ZnunyManualTicketCloseCandidateService::class);
        $service->review();
        $this->assertTrue(true);
    }
}
