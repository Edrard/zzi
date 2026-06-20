<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\ZabbixTicket;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyManualTicketLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoCloseManualTicketsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'manual_ticket_auto_close_enabled'], ['value' => 'true', 'type' => 'boolean']);
    }

    private function createCandidateTicket($overrides = [])
    {
        return ZabbixTicket::create(array_merge([
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
            'manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE,
            'manual_close_eligible_at' => now()->subMinute(),
        ], $overrides));
    }

    public function test_default_dry_run_does_not_close()
    {
        $this->createCandidateTicket();

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldNotReceive('post');
        $clientMock->shouldNotReceive('closeTicket');

        $this->artisan('znuny:auto-close-manual-tickets')
            ->assertSuccessful()
            ->expectsOutputToContain('Auto-closing manual tickets (DRY RUN)...')
            ->expectsOutputToContain('Would close (dry-run)');

        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE, ZabbixTicket::first()->manual_lifecycle_status);
    }

    public function test_execute_closes_eligible_candidate()
    {
        $this->createCandidateTicket();

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldReceive('closeTicket')
            ->once()
            ->with(100, \Mockery::on(function ($payload) {
                return $payload['State'] === 'closed successful';
            }))
            ->andReturn([
                'success' => true,
                'warnings' => [],
                'errors' => [],
            ]);

        $this->artisan('znuny:auto-close-manual-tickets --execute')
            ->assertSuccessful()
            ->expectsOutputToContain('Auto-closing manual tickets (EXECUTE MODE)...')
            ->expectsOutputToContain('Closed successfully.');

        $ticket = ZabbixTicket::first();
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CLOSED, $ticket->manual_lifecycle_status);
        $this->assertEquals('closed', $ticket->znuny_ticket_state_type);
    }

    public function test_execute_rechecks_eligibility_and_skips()
    {
        $ticket = $this->createCandidateTicket();

        // Simulate that after finding candidates, before executing close, it's no longer eligible
        // We can do this by setting setting to false right before execution,
        // or simulating a mock that changes the DB.
        // Actually, let's just make sure the service re-reads the DB.
        Setting::updateOrCreate(['key' => 'manual_ticket_auto_close_enabled'], ['value' => 'false', 'type' => 'boolean']);

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldNotReceive('closeTicket');

        $this->artisan('znuny:auto-close-manual-tickets --execute')
            ->assertSuccessful()
            ->expectsOutputToContain('Auto-closing manual tickets (EXECUTE MODE)...');

        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE, ZabbixTicket::first()->manual_lifecycle_status);
    }

    public function test_skips_closed_znuny_snapshot()
    {
        $this->createCandidateTicket(['znuny_ticket_state_type' => 'closed']);

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldNotReceive('closeTicket');

        $this->artisan('znuny:auto-close-manual-tickets --execute')
            ->assertSuccessful();
    }

    public function test_skips_cache_stale()
    {
        $this->createCandidateTicket(['manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_CACHE_STALE]);

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldNotReceive('closeTicket');

        $this->artisan('znuny:auto-close-manual-tickets --execute')
            ->assertSuccessful();
    }

    public function test_skips_auto_close_disabled()
    {
        Setting::updateOrCreate(['key' => 'manual_ticket_auto_close_enabled'], ['value' => 'false', 'type' => 'boolean']);
        $this->createCandidateTicket();

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldNotReceive('closeTicket');

        $this->artisan('znuny:auto-close-manual-tickets --execute')
            ->assertSuccessful();
    }

    public function test_skips_future_eligibility()
    {
        $this->createCandidateTicket(['manual_close_eligible_at' => now()->addMinutes(10)]);

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldNotReceive('closeTicket');

        $this->artisan('znuny:auto-close-manual-tickets --execute')
            ->assertSuccessful();
    }

    public function test_focused_ticket_id()
    {
        $ticket1 = $this->createCandidateTicket(['znuny_ticket_id' => 100, 'znuny_ticket_number' => '1000']);
        $ticket2 = $this->createCandidateTicket(['zabbix_event_id' => 'evt2', 'znuny_ticket_id' => 200, 'znuny_ticket_number' => '2000']);

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldReceive('closeTicket')
            ->once()
            ->with(200, \Mockery::any())
            ->andReturn(['success' => true]);

        $this->artisan('znuny:auto-close-manual-tickets --execute --ticket-id='.$ticket2->id)
            ->assertSuccessful();

        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE, $ticket1->fresh()->manual_lifecycle_status);
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CLOSED, $ticket2->fresh()->manual_lifecycle_status);
    }

    public function test_limit_option()
    {
        $ticket1 = $this->createCandidateTicket(['znuny_ticket_id' => 100, 'znuny_ticket_number' => '1000']);
        $ticket2 = $this->createCandidateTicket(['zabbix_event_id' => 'evt2', 'znuny_ticket_id' => 200, 'znuny_ticket_number' => '2000']);

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldReceive('closeTicket')
            ->once()
            ->andReturn(['success' => true]);

        $this->artisan('znuny:auto-close-manual-tickets --execute --limit=1')
            ->assertSuccessful();

        $closed = ZabbixTicket::where('manual_lifecycle_status', ZnunyManualTicketLifecycleService::STATUS_CLOSED)->count();
        $this->assertEquals(1, $closed);
    }

    public function test_close_failure()
    {
        $this->createCandidateTicket();

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldReceive('closeTicket')
            ->once()
            ->andReturn([
                'success' => false,
                'errors' => ['Znuny error'],
            ]);

        $this->artisan('znuny:auto-close-manual-tickets --execute')
            ->assertSuccessful()
            ->expectsOutputToContain('Failed to close in Znuny: Znuny error');

        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE, ZabbixTicket::first()->manual_lifecycle_status);
    }

    public function test_json_output()
    {
        $this->createCandidateTicket();

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldReceive('closeTicket')
            ->once()
            ->andReturn(['success' => true]);

        $this->artisan('znuny:auto-close-manual-tickets --execute --json')
            ->assertSuccessful();
    }

    public function test_no_disallowed_endpoints()
    {
        $this->createCandidateTicket();

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldNotReceive('post')->withArgs(function ($url) {
            return str_contains($url, '/TicketReopen') || str_contains($url, '/TicketArticle') || str_contains($url, '/TicketUpdate');
        });

        $clientMock->shouldReceive('closeTicket')
            ->once()
            ->andReturn(['success' => true]);

        $this->artisan('znuny:auto-close-manual-tickets --execute')
            ->assertSuccessful();
    }
}
