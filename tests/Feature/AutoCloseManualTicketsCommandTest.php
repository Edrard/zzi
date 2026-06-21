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
        Setting::updateOrCreate(['key' => 'manual_ticket_auto_close_schedule_mode'], ['value' => 'execute', 'type' => 'string']);
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
                return ! isset($payload['Article'])
                    && ! isset($payload['State'])
                    && isset($payload['Kind']) && $payload['Kind'] === 'internal_note'
                    && isset($payload['Subject'])
                    && isset($payload['Body'])
                    && isset($payload['Reason']);
            }))
            ->andReturn([
                'success' => true,
                'warnings' => [],
                'errors' => [],
            ]);

        $clientMock->shouldReceive('getTicket')
            ->once()
            ->with(100)
            ->andReturn([
                'StateType' => 'closed',
                'State' => 'closed successful',
            ]);

        $this->artisan('znuny:auto-close-manual-tickets --execute')
            ->assertSuccessful()
            ->expectsOutputToContain('Auto-closing manual tickets (EXECUTE MODE)...')
            ->expectsOutputToContain('Closed successfully.');

        $ticket = ZabbixTicket::first();
        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CLOSED, $ticket->manual_lifecycle_status);
        $this->assertEquals('closed', $ticket->znuny_ticket_state_type);
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

    public function test_skips_identity_missing()
    {
        $this->createCandidateTicket(['manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_IDENTITY_MISSING]);

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

        $clientMock->shouldReceive('getTicket')
            ->once()
            ->with(200)
            ->andReturn([
                'StateType' => 'closed',
                'State' => 'closed successful',
            ]);

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

        $clientMock->shouldReceive('getTicket')
            ->once()
            ->andReturn([
                'StateType' => 'closed',
                'State' => 'closed successful',
            ]);

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
            ->andReturn([
                'success' => true,
                'state' => 'closed successful',
                'state_type' => 'closed',
                'warnings' => [],
                'errors' => [],
            ]);

        $clientMock->shouldNotReceive('getTicket');

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

        $clientMock->shouldReceive('getTicket')
            ->once()
            ->andReturn([
                'StateType' => 'closed',
                'State' => 'closed successful',
            ]);

        $this->artisan('znuny:auto-close-manual-tickets --execute')
            ->assertSuccessful();
    }

    public function test_ambiguous_response_but_verified_closed()
    {
        $this->createCandidateTicket();

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldReceive('closeTicket')
            ->once()
            ->andThrow(new \Exception('Network timeout'));

        $clientMock->shouldReceive('getTicket')
            ->once()
            ->with(100)
            ->andReturn([
                'StateType' => 'closed',
                'State' => 'closed successful',
            ]);

        $this->artisan('znuny:auto-close-manual-tickets --execute')
            ->assertSuccessful()
            ->expectsOutputToContain('Closed successfully.');

        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CLOSED, ZabbixTicket::first()->manual_lifecycle_status);
    }

    public function test_ambiguous_response_and_verification_says_still_open()
    {
        $this->createCandidateTicket();

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldReceive('closeTicket')
            ->once()
            ->andThrow(new \Exception('Network timeout'));

        $clientMock->shouldReceive('getTicket')
            ->once()
            ->with(100)
            ->andReturn([
                'StateType' => 'open',
                'State' => 'open',
            ]);

        $this->artisan('znuny:auto-close-manual-tickets --execute')
            ->assertSuccessful()
            ->expectsOutputToContain('Ticket is still open after close attempt.');

        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE, ZabbixTicket::first()->manual_lifecycle_status);
    }

    public function test_ambiguous_response_and_verification_throws()
    {
        $this->createCandidateTicket();

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldReceive('closeTicket')
            ->once()
            ->andThrow(new \Exception('Network timeout'));

        $clientMock->shouldReceive('getTicket')
            ->once()
            ->with(100)
            ->andThrow(new \Exception('Network timeout'));

        $this->artisan('znuny:auto-close-manual-tickets --execute')
            ->assertSuccessful()
            ->expectsOutputToContain('Verification failed: Network timeout');

        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE, ZabbixTicket::first()->manual_lifecycle_status);
    }

    public function test_explicit_api_error_fails_immediately()
    {
        $this->createCandidateTicket();

        $clientMock = $this->mock(ZnunyClient::class);
        $clientMock->shouldReceive('closeTicket')
            ->once()
            ->andThrow(new \Exception('Znuny API Error: Invalid state'));

        $clientMock->shouldNotReceive('getTicket');

        $this->artisan('znuny:auto-close-manual-tickets --execute')
            ->assertSuccessful()
            ->expectsOutputToContain('Failed to close in Znuny: Znuny API Error: Invalid state');

        $this->assertEquals(ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE, ZabbixTicket::first()->manual_lifecycle_status);
    }
}
