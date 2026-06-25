<?php

namespace Tests\Feature\Console;

use App\Models\Setting;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class WarmZnunyTicketWorkspaceCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $client;

    private MockInterface $cacheService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->mock(ZnunyClient::class);
        $this->cacheService = $this->mock(ZnunyTicketCacheService::class);
    }

    public function test_it_exits_when_disabled(): void
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['value' => 'false']);

        $this->client->shouldNotReceive('searchTickets');

        $this->artisan('znuny:warm-ticket-workspace-cache')
            ->expectsOutput('Ticket Workspace is disabled in settings. Exiting cleanly.')
            ->assertSuccessful();
    }

    public function test_it_exits_when_no_active_states_configured(): void
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['value' => 'true']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_active_state_type_ids'], ['value' => '[]']);

        $this->client->shouldNotReceive('searchTickets');

        $this->artisan('znuny:warm-ticket-workspace-cache')
            ->expectsOutput('No active state type IDs configured. Exiting.')
            ->assertSuccessful();
    }

    public function test_it_exits_when_no_mapped_states_found(): void
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['value' => 'true']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_active_state_type_ids'], ['value' => '["unknown"]']);

        $this->client->shouldNotReceive('searchTickets');

        $this->artisan('znuny:warm-ticket-workspace-cache')
            ->expectsOutput('No matching Znuny StateTypes found for the configured IDs. Exiting.')
            ->assertSuccessful();
    }

    public function test_it_warms_cache_and_handles_pagination(): void
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['value' => 'true']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_active_state_type_ids'], ['value' => '["new"]']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_cache_default_limit'], ['value' => '2']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_cache_max_pages_per_run'], ['value' => '2']);

        // First we do a CountOnly query
        $this->client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->with([
                'StateType' => 'new',
                'CountOnly' => 1,
            ])
            ->andReturn([
                'total_count' => 3,
                'count_only' => true,
                'warnings' => [],
            ]);

        // First page returns 2 tickets (full)
        $this->client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->with([
                'StateType' => 'new',
                'Limit' => 2,
                'Offset' => 0,
                'SortBy' => 'Changed',
                'SortDirection' => 'DESC',
            ])
            ->andReturn([
                'tickets' => [
                    ['TicketID' => 1],
                    ['TicketID' => 2],
                ],
                'warnings' => [],
            ]);

        // Second page returns 1 ticket (partial, will stop pagination)
        $this->client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->with([
                'StateType' => 'new',
                'Limit' => 2,
                'Offset' => 2,
                'SortBy' => 'Changed',
                'SortDirection' => 'DESC',
            ])
            ->andReturn([
                'tickets' => [
                    ['TicketID' => 3],
                ],
                'warnings' => [],
            ]);

        $this->cacheService->shouldReceive('upsertOrRefreshFromSearchResult')->with(['TicketID' => 1])->andReturn('cached_new');
        $this->cacheService->shouldReceive('upsertOrRefreshFromSearchResult')->with(['TicketID' => 2])->andReturn('refreshed_unchanged');
        $this->cacheService->shouldReceive('upsertOrRefreshFromSearchResult')->with(['TicketID' => 3])->andReturn('updated_changed');

        $this->artisan('znuny:warm-ticket-workspace-cache')
            ->expectsOutput('Warming cache for StateTypes: new')
            ->expectsOutput('Total active tickets found: 3')
            ->expectsOutput('Cache warming complete.')
            ->expectsTable(['Metric', 'Count'], [
                ['state_types', 1],
                ['total_count', 3],
                ['count_only_requests', 1],
                ['pages_requested', 2],
                ['tickets_seen', 3],
                ['cached_new', 1],
                ['refreshed_unchanged', 1],
                ['updated_changed', 1],
                ['skipped_missing_ticket_id', 0],
                ['skipped_disabled', 0],
                ['errors', 0],
                ['warnings', 0],
            ])
            ->assertSuccessful();
    }

    public function test_it_exits_early_if_total_count_is_zero(): void
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['value' => 'true']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_active_state_type_ids'], ['value' => '["new"]']);

        $this->client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->with([
                'StateType' => 'new',
                'CountOnly' => 1,
            ])
            ->andReturn([
                'total_count' => 0,
                'warnings' => [],
            ]);

        $this->artisan('znuny:warm-ticket-workspace-cache')
            ->expectsOutput('No active tickets found. Exiting cleanly.')
            ->expectsTable(['Metric', 'Count'], [
                ['state_types', 1],
                ['total_count', 0],
                ['count_only_requests', 1],
                ['pages_requested', 0],
                ['tickets_seen', 0],
                ['cached_new', 0],
                ['refreshed_unchanged', 0],
                ['updated_changed', 0],
                ['skipped_missing_ticket_id', 0],
                ['skipped_disabled', 0],
                ['errors', 0],
                ['warnings', 0],
            ])
            ->assertSuccessful();
    }

    public function test_it_handles_per_ticket_errors_without_crashing(): void
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['value' => 'true']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_active_state_type_ids'], ['value' => '["new"]']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_cache_default_limit'], ['value' => '2']);

        $this->client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->with([
                'StateType' => 'new',
                'CountOnly' => 1,
            ])
            ->andReturn([
                'total_count' => 1,
                'warnings' => ['A weird Znuny warning'],
            ]);

        // Return 1 ticket so it doesn't trigger a second page request
        $this->client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->andReturn([
                'tickets' => [
                    ['TicketID' => 1],
                ],
                'warnings' => [],
            ]);

        $this->cacheService->shouldReceive('upsertOrRefreshFromSearchResult')->with(['TicketID' => 1])->andThrow(new \Exception('Bad cache payload'));

        $this->artisan('znuny:warm-ticket-workspace-cache')
            ->expectsOutputToContain('Error caching ticket: Bad cache payload')
            ->expectsTable(['Metric', 'Count'], [
                ['state_types', 1],
                ['total_count', 1],
                ['count_only_requests', 1],
                ['pages_requested', 1],
                ['tickets_seen', 1],
                ['cached_new', 0],
                ['refreshed_unchanged', 0],
                ['updated_changed', 0],
                ['skipped_missing_ticket_id', 0],
                ['skipped_disabled', 0],
                ['errors', 1],
                ['warnings', 1],
            ])
            ->assertSuccessful();
    }

    public function test_it_handles_skipped_disabled_without_crashing(): void
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['value' => 'true']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_active_state_type_ids'], ['value' => '["new"]']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_cache_default_limit'], ['value' => '2']);

        $this->client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->with([
                'StateType' => 'new',
                'CountOnly' => 1,
            ])
            ->andReturn([
                'total_count' => 1,
                'warnings' => [],
            ]);

        $this->client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->andReturn([
                'tickets' => [
                    ['TicketID' => 1],
                ],
                'warnings' => [],
            ]);

        $this->cacheService->shouldReceive('upsertOrRefreshFromSearchResult')->with(['TicketID' => 1])->andReturn('skipped_disabled');

        $this->artisan('znuny:warm-ticket-workspace-cache')
            ->expectsTable(['Metric', 'Count'], [
                ['state_types', 1],
                ['total_count', 1],
                ['count_only_requests', 1],
                ['pages_requested', 1],
                ['tickets_seen', 1],
                ['cached_new', 0],
                ['refreshed_unchanged', 0],
                ['updated_changed', 0],
                ['skipped_missing_ticket_id', 0],
                ['skipped_disabled', 1],
                ['errors', 0],
                ['warnings', 0],
            ])
            ->assertSuccessful();
    }
}
