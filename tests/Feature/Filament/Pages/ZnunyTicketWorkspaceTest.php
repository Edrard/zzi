<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Pages\ZnunyTicketWorkspace;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Znuny\ClosedTicketCacheService;
use App\Services\Znuny\ClosedTicketSyncService;
use App\Services\Znuny\ZnunyTicketCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

class ZnunyTicketWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::flushall();
    }

    protected function seedTicket(array $ticketOverrides): void
    {
        $ticket = array_merge([
            'TicketID' => 1,
            'TicketNumber' => '100000000',
            'Title' => 'Default',
            'QueueID' => 1,
            'Queue' => 'Raw',
            'OwnerID' => 1,
            'Owner' => 'Admin',
            'StateID' => 1,
            'State' => 'new',
            'StateType' => 'new',
            'PriorityID' => 1,
            'Priority' => '3 normal',
            'TypeID' => 1,
            'Type' => 'Unclassified',
            'Changed' => now()->toDateTimeString(),
            'Created' => now()->subDay()->toDateTimeString(),
            'ArticleCount' => 1,
        ], $ticketOverrides);

        app(ZnunyTicketCacheService::class)->upsertOrRefreshFromSearchResult($ticket);
    }

    public function test_it_renders_without_calling_znuny_api()
    {
        $user = User::factory()->create(['role' => 'operator']);

        // Fake Znuny API to fail if called to ensure UI does not call it
        Http::fake([
            '*' => Http::response('Should not be called', 500),
        ]);

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Test Ticket', 'StateType' => 'open']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertSee('TN101')
            ->assertSee('Test Ticket');

        Http::assertNothingSent();
    }

    public function test_empty_state_shows_correct_message()
    {
        $user = User::factory()->create(['role' => 'operator']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->set('stateTypeFilter', [])
            ->assertSee('Ticket cache is empty')
            ->assertSee('Run the Ticket Workspace cache warmer');
    }

    public function test_it_applies_livewire_filters()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Apple issue', 'StateType' => 'new']);
        $this->seedTicket(['TicketID' => 102, 'TicketNumber' => 'TN102', 'Title' => 'Banana issue', 'StateType' => 'closed']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new', 'closed'])
            ->set('search', 'Apple')
            ->assertSee('TN101')
            ->assertDontSee('TN102')
            ->set('search', '')
            ->set('stateTypeFilter', ['closed'])
            ->assertSee('TN102')
            ->assertDontSee('TN101');
    }

    public function test_it_sorts_tickets_correctly()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'A Ticket', 'Changed' => '2023-01-01 10:00:00', 'StateType' => 'new']);
        $this->seedTicket(['TicketID' => 102, 'TicketNumber' => 'TN102', 'Title' => 'Z Ticket', 'Changed' => '2023-01-02 10:00:00', 'StateType' => 'new']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new'])
            ->call('sortBy', 'Title')
            ->assertSeeInOrder(['Z Ticket', 'A Ticket']);
    }

    public function test_it_paginates_and_limits_per_page()
    {
        $user = User::factory()->create(['role' => 'operator']);

        for ($i = 1; $i <= 51; $i++) {
            $this->seedTicket(['TicketID' => 100 + $i, 'TicketNumber' => 'TN'.(100 + $i), 'Title' => 'Ticket '.$i, 'StateType' => 'new']);
        }

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new'])
            ->set('perPage', 50)
            ->assertSee('TN151')
            ->assertDontSee('TN101') // on page 2
            ->set('page', 2)
            ->assertDontSee('TN151')
            ->assertSee('TN101');
    }

    public function test_queue_and_owner_filters_work()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Q10_O20', 'QueueID' => 10, 'OwnerID' => 20, 'StateType' => 'new']);
        $this->seedTicket(['TicketID' => 102, 'TicketNumber' => 'TN102', 'Title' => 'Q11_O21', 'QueueID' => 11, 'OwnerID' => 21, 'StateType' => 'new']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new'])
            ->set('queueFilter', 10)
            ->assertSee('TN101')
            ->assertDontSee('TN102')
            ->set('queueFilter', null)
            ->set('ownerFilter', 21)
            ->assertSee('TN102')
            ->assertDontSee('TN101');
    }

    public function test_clicking_row_opens_details_modal_with_cached_data()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Test Details', 'StateType' => 'new', 'CustomerUserID' => 'client@example.com']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new'])
            ->mountAction('viewTicket', ['znuny_ticket_id' => 101])
            ->assertActionMounted('viewTicket')
            ->assertSee('client@example.com');
    }

    public function test_icon_legend_is_rendered()
    {
        $user = User::factory()->create(['role' => 'operator']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertSee('Linked to active Zabbix problem')
            ->assertSee('Linked to resolved Zabbix problem')
            ->assertSee('Active problem on closed/merged ticket');
    }

    public function test_state_type_filter_accepts_multiple_values()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 201, 'TicketNumber' => 'TN201', 'Title' => 'New', 'StateType' => 'new']);
        $this->seedTicket(['TicketID' => 202, 'TicketNumber' => 'TN202', 'Title' => 'Open', 'StateType' => 'open']);
        $this->seedTicket(['TicketID' => 203, 'TicketNumber' => 'TN203', 'Title' => 'Closed', 'StateType' => 'closed']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new', 'open'])
            ->assertSee('TN201')
            ->assertSee('TN202')
            ->assertDontSee('TN203');
    }

    public function test_render_closed_tickets_only_reads_from_cache()
    {
        $user = User::factory()->create(['role' => 'operator']);

        // Seed an active ticket just to prove it doesn't show up
        $this->seedTicket(['TicketID' => 201, 'TicketNumber' => 'TN201', 'Title' => 'New Ticket', 'StateType' => 'new']);

        // Mock ClosedTicketCacheService to return a closed ticket
        $mock = \Mockery::mock(ClosedTicketCacheService::class)->makePartial();
        $mock->shouldReceive('getRecentTicketIds')->andReturn([301]);
        $this->app->instance(ClosedTicketCacheService::class, $mock);

        // Put closed ticket payload in Redis
        Redis::set('znuny:closed_ticket:ticket:301', json_encode([
            'TicketID' => 301,
            'TicketNumber' => 'TN301',
            'Title' => 'Closed Ticket from Cache',
            'StateType' => 'closed',
            'Changed' => '2023-10-01 12:00:00',
        ]));

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['closed'])
            ->assertDontSee('TN201') // Active ticket not shown
            ->assertSee('TN301') // Closed ticket shown
            ->assertSee('Closed Ticket from Cache');
    }

    public function test_mixed_filter_renders_both_active_and_closed()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 201, 'TicketNumber' => 'TN201', 'Title' => 'New Ticket', 'StateType' => 'new']);

        $mock = \Mockery::mock(ClosedTicketCacheService::class)->makePartial();
        $mock->shouldReceive('getRecentTicketIds')->andReturn([301, 201]); // 201 added to test deduplication
        $this->app->instance(ClosedTicketCacheService::class, $mock);

        Redis::set('znuny:closed_ticket:ticket:301', json_encode([
            'TicketID' => 301,
            'TicketNumber' => 'TN301',
            'Title' => 'Closed Ticket from Cache',
            'StateType' => 'closed',
            'Changed' => '2023-10-01 12:00:00',
        ]));

        Redis::set('znuny:closed_ticket:ticket:201', json_encode([
            'TicketID' => 201,
            'TicketNumber' => 'TN201',
            'Title' => 'New Ticket Duplicate in Closed',
            'StateType' => 'closed',
        ]));

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new', 'closed'])
            ->assertSee('TN201')
            ->assertSee('New Ticket') // Active payload is preferred during deduplication if it comes first, or deduplicated correctly.
            ->assertSee('TN301')
            ->assertSee('Closed Ticket from Cache');
    }

    public function test_default_empty_filter_excludes_closed_tickets()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 201, 'TicketNumber' => 'TN201', 'Title' => 'New Ticket', 'StateType' => 'new']);

        $mock = \Mockery::mock(ClosedTicketCacheService::class)->makePartial();
        $mock->shouldReceive('getRecentTicketIds')->andReturn([301]);
        $this->app->instance(ClosedTicketCacheService::class, $mock);

        Redis::set('znuny:closed_ticket:ticket:301', json_encode([
            'TicketID' => 301,
            'TicketNumber' => 'TN301',
            'Title' => 'Closed Ticket from Cache',
            'StateType' => 'closed',
            'Changed' => '2023-10-01 12:00:00',
        ]));

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', []) // Empty filter
            ->assertSee('TN201') // Active ticket shown
            ->assertDontSee('TN301') // Closed ticket NOT shown
            ->assertDontSee('Closed Ticket from Cache');
    }

    public function test_deduplication_prefers_newer_changed_timestamp()
    {
        $user = User::factory()->create(['role' => 'operator']);

        // Active cache ticket is older
        $this->seedTicket(['TicketID' => 201, 'TicketNumber' => 'TN201', 'Title' => 'Older Active', 'StateType' => 'open', 'Changed' => '2023-10-01 10:00:00']);

        $mock = \Mockery::mock(ClosedTicketCacheService::class)->makePartial();
        $mock->shouldReceive('getRecentTicketIds')->andReturn([201]);
        $this->app->instance(ClosedTicketCacheService::class, $mock);

        // Closed cache ticket is newer
        Redis::set('znuny:closed_ticket:ticket:201', json_encode([
            'TicketID' => 201,
            'TicketNumber' => 'TN201',
            'Title' => 'Newer Closed',
            'StateType' => 'closed',
            'Changed' => '2023-10-01 12:00:00',
        ]));

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['open', 'closed'])
            ->assertSee('Newer Closed')
            ->assertDontSee('Older Active');
    }

    public function test_deduplication_prefers_active_payload_if_changed_equal()
    {
        $user = User::factory()->create(['role' => 'operator']);

        // Active cache ticket
        $this->seedTicket(['TicketID' => 201, 'TicketNumber' => 'TN201', 'Title' => 'Active Payload', 'StateType' => 'open', 'Changed' => '2023-10-01 12:00:00']);

        $mock = \Mockery::mock(ClosedTicketCacheService::class)->makePartial();
        $mock->shouldReceive('getRecentTicketIds')->andReturn([201]);
        $this->app->instance(ClosedTicketCacheService::class, $mock);

        // Closed cache ticket with exact same timestamp
        Redis::set('znuny:closed_ticket:ticket:201', json_encode([
            'TicketID' => 201,
            'TicketNumber' => 'TN201',
            'Title' => 'Closed Payload',
            'StateType' => 'closed',
            'Changed' => '2023-10-01 12:00:00',
        ]));

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['open', 'closed'])
            ->assertSee('Active Payload')
            ->assertDontSee('Closed Payload');
    }

    public function test_search_closed_ticket()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $mock = \Mockery::mock(ClosedTicketCacheService::class)->makePartial();
        $mock->shouldReceive('getRecentTicketIds')->andReturn([401]);
        $this->app->instance(ClosedTicketCacheService::class, $mock);

        Redis::set('znuny:closed_ticket:ticket:401', json_encode([
            'TicketID' => 401,
            'TicketNumber' => 'TN401',
            'Title' => 'Unique Secret Title',
            'StateType' => 'closed',
            'Changed' => '2023-10-01 12:00:00',
        ]));

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['closed'])
            ->set('search', 'Secret')
            ->assertSee('TN401')
            ->set('search', 'NonMatching')
            ->assertDontSee('TN401');
    }

    public function test_queue_filter_closed_ticket()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $mock = \Mockery::mock(ClosedTicketCacheService::class)->makePartial();
        $mock->shouldReceive('getRecentTicketIds')->andReturn([401, 402]);
        $this->app->instance(ClosedTicketCacheService::class, $mock);

        Redis::set('znuny:closed_ticket:ticket:401', json_encode([
            'TicketID' => 401,
            'TicketNumber' => 'TN401',
            'QueueID' => 10,
            'Queue' => 'Support',
            'Title' => 'Support Ticket',
            'StateType' => 'closed',
            'Changed' => '2023-10-01 12:00:00',
        ]));

        Redis::set('znuny:closed_ticket:ticket:402', json_encode([
            'TicketID' => 402,
            'TicketNumber' => 'TN402',
            'QueueID' => 20,
            'Queue' => 'Sales',
            'Title' => 'Sales Ticket',
            'StateType' => 'closed',
            'Changed' => '2023-10-01 12:00:00',
        ]));

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['closed'])
            ->set('queueFilter', 10)
            ->assertSee('TN401')
            ->assertDontSee('TN402');
    }

    public function test_owner_filter_closed_ticket()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $mock = \Mockery::mock(ClosedTicketCacheService::class)->makePartial();
        $mock->shouldReceive('getRecentTicketIds')->andReturn([401, 402]);
        $this->app->instance(ClosedTicketCacheService::class, $mock);

        Redis::set('znuny:closed_ticket:ticket:401', json_encode([
            'TicketID' => 401,
            'TicketNumber' => 'TN401',
            'OwnerID' => 50,
            'Owner' => 'Alice',
            'Title' => 'Alice Ticket',
            'StateType' => 'closed',
            'Changed' => '2023-10-01 12:00:00',
        ]));

        Redis::set('znuny:closed_ticket:ticket:402', json_encode([
            'TicketID' => 402,
            'TicketNumber' => 'TN402',
            'OwnerID' => 60,
            'Owner' => 'Bob',
            'Title' => 'Bob Ticket',
            'StateType' => 'closed',
            'Changed' => '2023-10-01 12:00:00',
        ]));

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['closed'])
            ->set('ownerFilter', 60)
            ->assertSee('TN402')
            ->assertDontSee('TN401');
    }

    public function test_sort_by_changed_works_for_closed_tickets()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $mock = \Mockery::mock(ClosedTicketCacheService::class)->makePartial();
        $mock->shouldReceive('getRecentTicketIds')->andReturn([401, 402]);
        $this->app->instance(ClosedTicketCacheService::class, $mock);

        Redis::set('znuny:closed_ticket:ticket:401', json_encode([
            'TicketID' => 401,
            'TicketNumber' => 'TN401',
            'Title' => 'Older',
            'StateType' => 'closed',
            'Changed' => '2023-10-01 10:00:00',
        ]));

        Redis::set('znuny:closed_ticket:ticket:402', json_encode([
            'TicketID' => 402,
            'TicketNumber' => 'TN402',
            'Title' => 'Newer',
            'StateType' => 'closed',
            'Changed' => '2023-10-01 12:00:00',
        ]));

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['closed'])
            ->set('sortField', 'Changed')
            ->set('sortDirection', 'desc');

        $data = $component->instance()->ticketData();
        $rows = $data['rows'] ?? [];
        $this->assertCount(2, $rows);
        $this->assertEquals(402, $rows[0]['TicketID']);
        $this->assertEquals(401, $rows[1]['TicketID']);
    }

    public function test_get_refresh_interval_string_uses_global_ui_polling_setting(): void
    {
        config(['app.ui_poll_interval_seconds' => 120]);

        $page = new ZnunyTicketWorkspace;
        $this->assertEquals('120s', $page->getRefreshIntervalString());
    }

    public function test_page_includes_livewire_polling()
    {
        $user = User::factory()->create(['role' => 'operator']);

        config(['app.ui_poll_interval_seconds' => 120]);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSeeHtml('wire:poll.120s');
    }

    public function test_single_refresh_action_renders_and_separate_closed_sync_is_removed()
    {
        $user = User::factory()->create(['role' => 'operator']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertActionExists('refresh')
            ->assertActionDoesNotExist('syncClosedTickets');
    }

    public function test_manual_refresh_success_for_both_active_and_closed()
    {
        $user = User::factory()->create(['role' => 'operator']);

        Artisan::shouldReceive('call')
            ->once()
            ->with('znuny:warm-ticket-workspace-cache', ['--manual' => true])
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->andReturn('Cache warming complete.');

        $mock = \Mockery::mock(ClosedTicketSyncService::class);
        $mock->shouldReceive('syncManual')->once()->andReturn([
            'mode' => 'manual',
            'effective_mode' => 'small',
            'fetched_count' => 10,
            'cached_count' => 10,
        ]);
        $this->app->instance(ClosedTicketSyncService::class, $mock);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->call('refreshFromZnuny')
            ->assertSet('page', 1)
            ->assertNotified('Ticket Workspace refreshed successfully');
    }

    public function test_manual_refresh_active_success_closed_skipped()
    {
        $user = User::factory()->create(['role' => 'operator']);

        Artisan::shouldReceive('call')
            ->once()
            ->with('znuny:warm-ticket-workspace-cache', ['--manual' => true])
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->andReturn('Cache warming complete.');

        $mock = \Mockery::mock(ClosedTicketSyncService::class);
        $mock->shouldReceive('syncManual')->once()->andReturn([
            'mode' => 'manual',
            'effective_mode' => 'skipped',
            'reason' => 'locked',
        ]);
        $this->app->instance(ClosedTicketSyncService::class, $mock);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->call('refreshFromZnuny')
            ->assertSet('page', 1)
            ->assertNotified('Ticket Workspace refreshed successfully');
    }

    public function test_manual_refresh_active_success_closed_error()
    {
        $user = User::factory()->create(['role' => 'operator']);

        Artisan::shouldReceive('call')
            ->once()
            ->with('znuny:warm-ticket-workspace-cache', ['--manual' => true])
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->andReturn('Cache warming complete.');

        $mock = \Mockery::mock(ClosedTicketSyncService::class);
        $mock->shouldReceive('syncManual')->once()->andReturn([
            'error_message' => 'Znuny API failed',
        ]);
        $this->app->instance(ClosedTicketSyncService::class, $mock);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->call('refreshFromZnuny')
            ->assertSet('page', 1)
            ->assertNotified('Ticket Workspace refreshed successfully');
    }

    public function test_manual_refresh_active_failure_skips_closed()
    {
        $user = User::factory()->create(['role' => 'operator']);

        Artisan::shouldReceive('call')
            ->once()
            ->with('znuny:warm-ticket-workspace-cache', ['--manual' => true])
            ->andReturn(1);
        Artisan::shouldReceive('output')
            ->andReturn('Failed output');

        $mock = \Mockery::mock(ClosedTicketSyncService::class);
        $mock->shouldNotReceive('syncManual');
        $this->app->instance(ClosedTicketSyncService::class, $mock);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->call('refreshFromZnuny')
            ->assertNotified('Failed to refresh Ticket Workspace');
    }

    public function test_manual_refresh_authorization()
    {
        $user = User::factory()->create(['role' => 'viewer']); // Not admin/operator

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->call('refreshFromZnuny')
            ->assertForbidden();
    }

    public function test_audit_logs_are_not_created_on_ui_render()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $initialCount = AuditLog::count();

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->call('$refresh');

        $this->assertEquals($initialCount, AuditLog::count());
    }

    public function test_ticket_number_column_has_responsive_class_and_css()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Test Ticket', 'StateType' => 'new']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertSeeHtml('class="zbx-col-ticket-number"')
            ->assertSeeHtml('@media (max-width: 1500px)')
            ->assertSeeHtml('.zbx-ticket-workspace .zbx-col-ticket-number')
            ->assertSeeHtml('display: none;');
    }

    public function test_priority_and_articles_columns_have_responsive_class_and_css()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Test Ticket', 'StateType' => 'new']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertSeeHtml('class="zbx-col-priority"')
            ->assertSeeHtml('class="zbx-col-articles"')
            ->assertSeeHtml('@media (max-width: 1350px)')
            ->assertSeeHtml('.zbx-ticket-workspace .zbx-col-priority,')
            ->assertSeeHtml('.zbx-ticket-workspace .zbx-col-articles')
            ->assertSeeHtml('display: none;');
    }

    public function test_changed_column_does_not_show_ago()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket([
            'TicketID' => 101,
            'TicketNumber' => 'TN101',
            'Title' => 'Changed Test',
            'StateType' => 'new',
            'Changed' => now()->subHours(2)->toDateTimeString(),
        ]);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertSee('2h')
            ->assertDontSee('2h ago');
    }

    public function test_owner_and_state_columns_have_responsive_class_and_css()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Test Ticket', 'StateType' => 'new']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertSeeHtml('class="zbx-col-owner"')
            ->assertSeeHtml('class="zbx-col-state"')
            ->assertSeeHtml('@media (max-width: 850px)')
            ->assertSeeHtml('.zbx-ticket-workspace .zbx-col-owner,')
            ->assertSeeHtml('.zbx-ticket-workspace .zbx-col-state');
    }

    public function test_changed_column_has_responsive_class_and_css()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Test Ticket', 'StateType' => 'new']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertSeeHtml('class="zbx-col-changed"')
            ->assertSeeHtml('@media (max-width: 500px)')
            ->assertSeeHtml('.zbx-ticket-workspace .zbx-col-changed');
    }

    public function test_workspace_css_variables_and_dropdown_fallbacks()
    {
        $user = User::factory()->create(['role' => 'operator']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertSeeHtml('.zbx-ticket-workspace {')
            ->assertSeeHtml('--zbx-table-bg: var(--color-white);')
            ->assertSeeHtml('--zbx-table-border: var(--gray-200);')
            ->assertSeeHtml('--zbx-table-text: var(--gray-950);')
            ->assertSeeHtml('--zbx-table-muted: var(--gray-500);')
            ->assertSeeHtml('.zbx-dropdown-menu {')
            ->assertSeeHtml('background-color: var(--zbx-table-bg, #ffffff);')
            ->assertSeeHtml('border: 1px solid var(--zbx-table-border, #e5e7eb);');
    }

    public function test_recent_closed_ticket_status_is_rendered_when_enabled()
    {
        config(['znuny.closed_ticket_status_panel_enabled' => true]);

        $user = User::factory()->create(['role' => 'operator']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertSee('Recent Closed Ticket Cache Status')
            ->assertSee('Recent closed ticket cache has not completed a full sync yet.');
    }

    public function test_recent_closed_ticket_status_is_hidden_by_default()
    {
        config(['znuny.closed_ticket_status_panel_enabled' => false]);
        $user = User::factory()->create(['role' => 'operator']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertDontSee('Recent Closed Ticket Cache Status');
    }

    public function test_recent_closed_ticket_status_complete_metadata_is_rendered_when_enabled()
    {
        config(['znuny.closed_ticket_status_panel_enabled' => true]);
        Setting::updateOrCreate(['key' => 'app_display_timezone'], ['value' => 'Asia/Tokyo']);

        $user = User::factory()->create(['role' => 'operator']);

        $mock = \Mockery::mock(ClosedTicketCacheService::class)->makePartial();
        $mock->shouldReceive('getMetadata')->andReturn([
            'integrity_status' => 'complete',
            'window_days' => 30,
            'retention_days' => 180,
            'last_mode' => 'full',
            'last_reason' => 'metadata_missing',
            'last_small_completed_at' => '2026-06-28 10:00:00', // UTC
            'last_full_completed_at' => '2026-06-28 09:00:00', // UTC
            'oldest_loaded_closed_at' => '2026-05-29 00:00:00', // UTC
            'newest_loaded_closed_at' => '2026-06-28 09:59:00', // UTC
            'last_run_started_at' => '2026-06-28 09:58:00', // UTC
            'last_run_completed_at' => '2026-06-28 10:00:00', // UTC
            'last_error' => 'Previous sync warning',
        ]);
        $this->app->instance(ClosedTicketCacheService::class, $mock);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertSee('Recent Closed Ticket Cache Status')
            ->assertSee('complete')
            ->assertSee('Window Days')
            ->assertSee('30')
            ->assertSee('Retention Days')
            ->assertSee('180')
            ->assertSee('Last Mode')
            ->assertSee('full')
            ->assertSee('Last Reason')
            ->assertSee('metadata_missing')
            ->assertSee('Last Small Completed At')
            ->assertSee('Jun 28, 2026 19:00:00') // 10:00 + 9 hours
            ->assertSee('Last Full Completed At')
            ->assertSee('Jun 28, 2026 18:00:00') // 09:00 + 9 hours
            ->assertSee('Oldest Loaded Closed At')
            ->assertSee('May 29, 2026 09:00:00') // 00:00 + 9 hours
            ->assertSee('Newest Loaded Closed At')
            ->assertSee('Jun 28, 2026 18:59:00') // 09:59 + 9 hours
            ->assertSee('Last Run Started At')
            ->assertSee('Jun 28, 2026 18:58:00') // 09:58 + 9 hours
            ->assertSee('Last Run Completed At')
            ->assertSee('Jun 28, 2026 19:00:00') // 10:00 + 9 hours
            ->assertDontSee('Asia/Tokyo')
            ->assertSee('Last Error')
            ->assertSee('Previous sync warning');
    }

    public function test_recent_closed_ticket_status_lock_is_rendered_when_enabled()
    {
        config(['znuny.closed_ticket_status_panel_enabled' => true]);
        $user = User::factory()->create(['role' => 'operator']);

        Cache::put('znuny:closed_ticket:sync:lock', true, 10);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertSee('Sync is currently running.');
    }
}
