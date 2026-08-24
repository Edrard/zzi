<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Pages\ZnunyTicketWorkspace;
use App\Filament\Resources\ZabbixTickets\Schemas\ZabbixTicketInfolist;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\Znuny\ClosedTicketCacheService;
use App\Services\Znuny\ClosedTicketSyncService;
use App\Services\Znuny\ZnunyAgentService;
use App\Services\Znuny\ZnunyAssignmentDependencyService;
use App\Services\Znuny\ZnunyCachedLookupService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyLinkedTicketReopenService;
use App\Services\Znuny\ZnunyTicketArticleWriteService;
use App\Services\Znuny\ZnunyTicketCacheService;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
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
        Redis::flushdb();
    }

    protected function setupChangeAssignmentDependencies(array $validOwners = ['new.owner' => 'new.owner', 'old.owner' => 'old.owner'], array $validQueues = ['Different Queue' => 'Different Queue', 'old.queue' => 'old.queue', 'new.queue' => 'new.queue'])
    {
        $lookupMock = \Mockery::mock(ZnunyCachedLookupService::class)->makePartial();
        $lookupMock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
        $lookupMock->shouldReceive('getCustomerUserLabel')->with('customer.1')->andReturn('customer.1')->byDefault();
        $this->app->instance(ZnunyCachedLookupService::class, $lookupMock);

        $depMock = \Mockery::mock(ZnunyAssignmentDependencyService::class)->makePartial();
        $depMock->shouldReceive('getOwnerLoginOptionsForQueue')->andReturn($validOwners)->byDefault();
        $depMock->shouldReceive('getQueueOptionsForOwnerLogin')->andReturn($validQueues)->byDefault();
        $this->app->instance(ZnunyAssignmentDependencyService::class, $depMock);

        $agentMock = \Mockery::mock(ZnunyAgentService::class)->makePartial();
        $agentMock->shouldReceive('isLoginExcluded')->andReturn(false)->byDefault();
        $this->app->instance(ZnunyAgentService::class, $agentMock);
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
            ->assertSee(__('znuny_ticket_workspace.empty_states.no_tickets'))
            ->assertSee(__('znuny_ticket_workspace.empty_states.no_tickets_description'));
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

    public function test_queue_and_owner_filters_have_faceted_semantics()
    {
        $user = User::factory()->create(['role' => 'operator']);

        // Q10: O20, O21
        // Q11: O21, O22
        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'QueueID' => 10, 'OwnerID' => 20, 'StateType' => 'new']);
        $this->seedTicket(['TicketID' => 102, 'TicketNumber' => 'TN102', 'QueueID' => 10, 'OwnerID' => 21, 'StateType' => 'new']);
        $this->seedTicket(['TicketID' => 103, 'TicketNumber' => 'TN103', 'QueueID' => 11, 'OwnerID' => 21, 'StateType' => 'new']);
        $this->seedTicket(['TicketID' => 104, 'TicketNumber' => 'TN104', 'QueueID' => 11, 'OwnerID' => 22, 'StateType' => 'new']);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new']);

        // 1. Initial state
        $data = $component->instance()->ticketData();
        $this->assertFacetKeys([10, 11], $data['filter_options']['queues']);
        $this->assertFacetKeys([20, 21, 22], $data['filter_options']['owners']);

        // Assert DOM identity (wire:key) contract
        $component->assertSeeHtml('wire:key="workspace-queue-option-any"')
            ->assertSeeHtml('wire:key="workspace-queue-option-10"')
            ->assertSeeHtml('wire:key="workspace-queue-option-11"')
            ->assertSeeHtml('wire:key="workspace-owner-option-any"')
            ->assertSeeHtml('wire:key="workspace-owner-option-20"')
            ->assertSeeHtml('wire:key="workspace-owner-option-21"')
            ->assertSeeHtml('wire:key="workspace-owner-option-22"');

        // 2. Select Queue 10
        $component->set('queueFilter', 10)
            ->assertSet('queueFilter', 10)
            ->assertSee('TN101')
            ->assertSee('TN102')
            ->assertDontSee('TN103')
            ->assertDontSee('TN104');

        $data = $component->instance()->ticketData();
        $this->assertFacetKeys([10, 11], $data['filter_options']['queues'], 'Queue options should not be self-filtered');
        $this->assertFacetKeys([20, 21], $data['filter_options']['owners'], 'Owner options should be restricted by active queue filter');

        // 3. Clear Queue 10 directly
        $component->set('queueFilter', '')
            ->assertSet('queueFilter', '')
            ->assertSee('TN101')
            ->assertSee('TN104');

        $data = $component->instance()->ticketData();
        $this->assertFacetKeys([20, 21, 22], $data['filter_options']['owners'], 'Owner options should expand after queue cleared');

        // 4. Select Owner 22
        $component->set('ownerFilter', 22)
            ->assertSet('ownerFilter', 22)
            ->assertSee('TN104')
            ->assertDontSee('TN101');

        $data = $component->instance()->ticketData();
        $this->assertFacetKeys([20, 21, 22], $data['filter_options']['owners'], 'Owner options should not be self-filtered');
        $this->assertFacetKeys([11], $data['filter_options']['queues'], 'Queue options should be restricted by active owner filter');

        // 5. Clear Owner 22
        $component->set('ownerFilter', '')
            ->assertSet('ownerFilter', '')
            ->assertSee('TN101')
            ->assertSee('TN104');

        // 6. Select Queue 11 + Owner 21
        $component->set('queueFilter', 11)
            ->set('ownerFilter', 21)
            ->assertSee('TN103')
            ->assertDontSee('TN101')
            ->assertDontSee('TN102')
            ->assertDontSee('TN104');

        $data = $component->instance()->ticketData();
        // Queue options = tickets under owner 21 = Queue 10, 11
        $this->assertFacetKeys([10, 11], $data['filter_options']['queues']);
        // Owner options = tickets under queue 11 = Owner 21, 22
        $this->assertFacetKeys([21, 22], $data['filter_options']['owners']);

        // 7. Clear queue while owner remains active
        $component->set('queueFilter', '')
            ->assertSee('TN102')
            ->assertSee('TN103')
            ->assertDontSee('TN101')
            ->assertDontSee('TN104');

        $data = $component->instance()->ticketData();
        $this->assertFacetKeys([10, 11], $data['filter_options']['queues']);
        $this->assertFacetKeys([20, 21, 22], $data['filter_options']['owners']);

        // 8. Clear owner while queue remains active (reset to Q11)
        $component->set('ownerFilter', '')
            ->set('queueFilter', 11)
            ->assertSee('TN103')
            ->assertSee('TN104')
            ->assertDontSee('TN101');

        $data = $component->instance()->ticketData();
        $this->assertFacetKeys([10, 11], $data['filter_options']['queues']);
        $this->assertFacetKeys([21, 22], $data['filter_options']['owners']);
    }

    protected function assertFacetKeys(array $expected, array $actualOptions, string $message = ''): void
    {
        $actual = array_keys($actualOptions);
        sort($expected);
        sort($actual);
        $this->assertEquals($expected, $actual, $message);
    }

    public function test_ticket_details_modal_renders_human_owner_name()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->setupChangeAssignmentDependencies(
            ['old.owner' => 'old.owner', 'ara@vamark.com' => 'Роман Андрушевич', 'unknown@vamark.com' => 'unknown@vamark.com'],
            ['Raw' => 'Raw']
        );

        app(ZnunyAssignmentDependencyService::class)
            ->shouldReceive('getOwnerOptionsForQueue')
            ->with(null)
            ->andReturn([99 => 'Роман Андрушевич'])
            ->byDefault();

        $this->seedTicket([
            'TicketID' => 101,
            'TicketNumber' => 'TN101',
            'Title' => 'Test Details',
            'StateType' => 'new',
            'Queue' => 'Raw',
            'OwnerID' => 99,
            'Owner' => 'ara@vamark.com',
            'CustomerUserID' => '2od@simple.eu',
            'Priority' => '3 normal',
        ]);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new'])
            ->mountAction('viewTicket', ['znuny_ticket_id' => 101])
            ->assertActionMounted('viewTicket');

        $action = $component->instance()->getMountedAction();
        $schema = Schema::make($component->instance());
        ZabbixTicketInfolist::configure($schema);

        $record = $action->evaluate($action->getRecord());
        $schema->record($record);

        $this->assertEquals('Роман Андрушевич', $schema->getComponent('znuny_owner_name')->getState());
        $this->assertEquals('2od@simple.eu', $schema->getComponent('customer_user')->getState());
        $this->assertEquals('Raw', $schema->getComponent('znuny_queue_name')->getState());
        $this->assertEquals('3 normal', $schema->getComponent('znuny_priority')->getState());

        // Test unresolvable
        $this->seedTicket([
            'TicketID' => 102,
            'TicketNumber' => 'TN102',
            'Title' => 'Test Details 2',
            'StateType' => 'new',
            'Queue' => 'Raw',
            'OwnerID' => 100, // not in dependencies
            'Owner' => 'unknown@vamark.com',
            'CustomerUserID' => '2od@simple.eu',
        ]);

        // Exercise the fallback as a fresh modal open. Reusing the same mounted
        // Filament action can retain the previous action record/state in the test harness.
        \App\Filament\Support\TicketDetailsPayload::clearCache();

        $component102 = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new'])
            ->mountAction('viewTicket', ['znuny_ticket_id' => 102])
            ->assertActionMounted('viewTicket');

        $action102 = $component102->instance()->getMountedAction();
        $record102 = $action102->evaluate($action102->getRecord());

        // Prove the mounted action itself resolved the second ticket before
        // evaluating the infolist display state.
        $payload102 = \App\Filament\Support\TicketDetailsPayload::fromRecord($record102);
        $this->assertEquals(100, $payload102->znuny_owner_id);
        $this->assertEquals('unknown@vamark.com', $payload102->znuny_owner_name);

        $schema102 = Schema::make($component102->instance());
        ZabbixTicketInfolist::configure($schema102);
        $schema102->record($record102);

        $this->assertEquals('unknown@vamark.com', $schema102->getComponent('znuny_owner_name')->getState());

    }

    public function test_clicking_row_opens_details_modal_with_cached_data()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Test Details', 'StateType' => 'new', 'CustomerUserID' => 'client@example.com']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new']);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new'])
            ->mountAction('viewTicket', ['znuny_ticket_id' => 101])
            ->assertActionMounted('viewTicket');

        $action = $component->instance()->getMountedAction();

        $openSubmitAction = $action->getModalSubmitAction();
        $this->assertNull($openSubmitAction, 'Submit action should be disabled');

        $footerActions = $action->getExtraModalFooterActions();
        $this->assertArrayHasKey('open_ticket', $footerActions, 'open_ticket should be in extra footer actions');

        $openAction = $footerActions['open_ticket'];
        $this->assertEquals(__('znuny_ticket_workspace.management_actions.open_ticket'), $openAction->getLabel());

        $attributes = $openAction->getExtraAttributes();
        $this->assertArrayHasKey('class', $attributes);
        $this->assertStringContainsString('zbx-open-ticket-footer-action', $attributes['class'], 'Open Ticket must have zbx-open-ticket-footer-action class to align right');
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
            'Changed' => now()->timezone('Europe/Kyiv')->subHours(2)->toDateTimeString(),
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

    public function test_recent_closed_ticket_status_is_rendered_for_admin()
    {
        $user = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertSee(__('znuny_ticket_workspace.cache_diagnostics.title'))
            ->assertSee(__('znuny_ticket_workspace.cache_diagnostics.not_completed_yet'));
    }

    public function test_recent_closed_ticket_status_is_hidden_for_operator()
    {
        $user = User::factory()->create(['role' => 'operator', 'show_znuny_closed_ticket_status_panel' => true]);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertDontSee('Recent Closed Ticket Cache Status');
    }

    public function test_recent_closed_ticket_status_is_hidden_for_viewer()
    {
        $user = User::factory()->create(['role' => 'viewer', 'show_znuny_closed_ticket_status_panel' => true]);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertDontSee('Recent Closed Ticket Cache Status');
    }

    public function test_recent_closed_ticket_status_is_hidden_for_admin_when_preference_disabled()
    {
        $user = User::factory()->create(['role' => 'admin', 'show_znuny_closed_ticket_status_panel' => false]);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertDontSee('Recent Closed Ticket Cache Status');
    }

    public function test_recent_closed_ticket_status_complete_metadata_is_rendered_for_admin()
    {
        Setting::updateOrCreate(['key' => 'app_display_timezone'], ['value' => 'Asia/Tokyo']);

        $user = User::factory()->create(['role' => 'admin']);

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
            ->assertSee(__('znuny_ticket_workspace.cache_diagnostics.title'))
            ->assertSee(__('znuny_ticket_workspace.cache_diagnostics.values.complete'))
            ->assertSee(__('znuny_ticket_workspace.cache_diagnostics.window_days'))
            ->assertSee('30')
            ->assertSee(__('znuny_ticket_workspace.cache_diagnostics.retention_days'))
            ->assertSee('180')
            ->assertSee(__('znuny_ticket_workspace.cache_diagnostics.last_mode'))
            ->assertSee(__('znuny_ticket_workspace.cache_diagnostics.values.full'))
            ->assertSee(__('znuny_ticket_workspace.cache_diagnostics.last_reason'))
            ->assertSee('metadata_missing')
            ->assertSee(__('znuny_ticket_workspace.cache_diagnostics.last_small_completed_at'))
            ->assertSee('Jun 28, 2026 19:00:00') // 10:00 + 9 hours
            ->assertSee(__('znuny_ticket_workspace.cache_diagnostics.last_full_completed_at'))
            ->assertSee('Jun 28, 2026 18:00:00') // 09:00 + 9 hours
            ->assertSee(__('znuny_ticket_workspace.cache_diagnostics.oldest_loaded_closed_at'))
            ->assertSee('May 29, 2026 09:00:00') // 00:00 + 9 hours
            ->assertSee(__('znuny_ticket_workspace.cache_diagnostics.newest_loaded_closed_at'))
            ->assertSee('Jun 28, 2026 18:59:00') // 09:59 + 9 hours
            ->assertSee(__('znuny_ticket_workspace.cache_diagnostics.last_run_started_at'))
            ->assertSee('Jun 28, 2026 18:58:00') // 09:58 + 9 hours
            ->assertSee(__('znuny_ticket_workspace.cache_diagnostics.last_run_completed_at'))
            ->assertSee('Jun 28, 2026 19:00:00') // 10:00 + 9 hours
            ->assertDontSee('Asia/Tokyo')
            ->assertSee(__('znuny_ticket_workspace.cache_diagnostics.last_error'))
            ->assertSee('Previous sync warning');
    }

    public function test_recent_closed_ticket_status_lock_is_rendered_for_admin()
    {
        $user = User::factory()->create(['role' => 'admin']);

        Cache::put('znuny:closed_ticket:sync:lock', true, 10);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertSee('Sync is currently running.');
    }

    public function test_successful_close_action_removes_ticket_from_open_list()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'First Ticket', 'StateType' => 'new']);
        $this->seedTicket(['TicketID' => 102, 'TicketNumber' => 'TN102', 'Title' => 'Second Ticket', 'StateType' => 'new']);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('closeTicket')->with(101, \Mockery::any())->andReturn(['success' => true]);
        $mockClient->shouldReceive('unlockTicket')->with(101)->andReturn(['success' => true]);
        $mockClient->shouldReceive('getTicket')->with(101)->andReturn([
            'TicketID' => 101,
            'TicketNumber' => 'TN101',
            'Title' => 'First Ticket',
            'StateType' => 'closed',
            'Created' => now()->subDay()->toIso8601String(),
            'Changed' => now()->toIso8601String(),
        ]);
        $mockClient->shouldReceive('getTicketArticles')->with(101)->andReturn([
            [
                'article_id' => 1,
                'ticket_id' => 101,
                'subject' => 'Article from close',
                'body' => 'Body content',
                'from' => 'System',
                'created_at' => now()->toIso8601String(),
            ],
        ]);
        $this->setupChangeAssignmentDependencies();
        $this->app->instance(ZnunyClient::class, $mockClient);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new'])
            ->assertSee('TN101')
            ->assertSee('TN102')
            ->mountAction('viewTicket', ['znuny_ticket_id' => 101]);

        // Assert footer actions for OPEN ticket
        $viewAction = $component->instance()->getMountedAction();
        $footerActions = $viewAction->getExtraModalFooterActions();
        $this->assertFalse($footerActions['manual_close_ticket']->isHidden(), 'Close Ticket should be visible for open ticket');
        $this->assertTrue($footerActions['reopen_ticket']->isHidden(), 'Reopen Ticket should be hidden for open ticket');
        $this->assertFalse($footerActions['add_note_or_article']->isHidden(), 'Add Note/Article should be visible for open ticket');

        $component->mountAction('manual_close_ticket', ['znuny_ticket_id' => 101])
            ->callMountedAction()
            ->assertNotified('Ticket Closed')
            ->assertActionNotMounted('viewTicket') // Parent action should be closed/unmounted
            ->assertDontSee('TN101')
            ->assertSee('TN102');

        $component2 = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 101])
            ->assertActionMounted('viewTicket');

        $viewActionClosed = $component2->instance()->getMountedAction();
        $record = $viewActionClosed->getRecord();
        $this->assertEquals(101, $record['TicketID'] ?? null);
        $this->assertEquals('TN101', $record['TicketNumber'] ?? null);
        $this->assertEquals('First Ticket', $record['Title'] ?? null);
        $this->assertEquals('closed', $record['StateType'] ?? null);
        $this->assertEquals(1, $record['ArticleCount'] ?? null);

        // Assert footer actions for CLOSED ticket
        $footerActionsClosed = $viewActionClosed->getExtraModalFooterActions();
        $this->assertTrue($footerActionsClosed['manual_close_ticket']->isHidden(), 'Close Ticket should be hidden for closed ticket');
        $this->assertFalse($footerActionsClosed['reopen_ticket']->isHidden(), 'Reopen Ticket should be visible for closed ticket');
        $this->assertTrue($footerActionsClosed['add_note_or_article']->isHidden(), 'Add Note/Article should be hidden for closed ticket');
    }

    public function test_failed_close_action_does_not_remove_ticket_from_open_list()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'First Ticket', 'StateType' => 'new']);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('closeTicket')->with(101, \Mockery::any())->andReturn(['success' => false, 'errors' => ['Znuny rejected close']]);
        $this->setupChangeAssignmentDependencies();
        $this->app->instance(ZnunyClient::class, $mockClient);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new'])
            ->assertSee('TN101')
            ->mountAction('viewTicket', ['znuny_ticket_id' => 101])
            ->mountAction('manual_close_ticket', ['znuny_ticket_id' => 101])
            ->setActionData(['reason' => 'Testing close failure'])
            ->callMountedAction()
            ->assertNotified('Close Failed')
            ->assertSee('TN101');
    }

    public function test_successful_reopen_action_moves_ticket_to_open_list()
    {
        $user = User::factory()->create(['role' => 'operator']);

        // Seed a closed ticket
        $this->seedTicket(['TicketID' => 103, 'TicketNumber' => 'TN103', 'Title' => 'Closed Ticket', 'StateType' => 'closed']);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('reopenTicket')->with(103, \Mockery::any())->andReturn(['success' => true]);
        $mockClient->shouldReceive('getTicket')->with(103)->andReturn([
            'TicketID' => 103,
            'TicketNumber' => 'TN103',
            'Title' => 'Closed Ticket',
            'StateType' => 'open',
            'Created' => now()->subDay()->toIso8601String(),
            'Changed' => now()->toIso8601String(),
        ]);
        $mockClient->shouldReceive('getTicketArticles')->with(103)->andReturn([
            [
                'article_id' => 2,
                'ticket_id' => 103,
                'subject' => 'Article from reopen',
                'body' => 'Body content',
                'from' => 'System',
                'created_at' => now()->toIso8601String(),
            ],
        ]);
        $this->setupChangeAssignmentDependencies();
        $this->app->instance(ZnunyClient::class, $mockClient);

        // For ZnunyLinkedTicketReopenService to use the mock
        $reopenServiceMock = \Mockery::mock(ZnunyLinkedTicketReopenService::class)->makePartial();
        $reopenServiceMock->shouldReceive('reopenTicket')->andReturn(['success' => true]);
        $this->app->instance(ZnunyLinkedTicketReopenService::class, $reopenServiceMock);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['closed'])
            ->assertSee('TN103')
            ->mountAction('viewTicket', ['znuny_ticket_id' => 103]);

        // Assert footer actions for CLOSED ticket
        $viewAction = $component->instance()->getMountedAction();
        $footerActions = $viewAction->getExtraModalFooterActions();
        $this->assertFalse($footerActions['reopen_ticket']->isHidden(), 'Reopen Ticket should be visible for closed ticket');
        $this->assertTrue($footerActions['manual_close_ticket']->isHidden(), 'Close Ticket should be hidden for closed ticket');
        $this->assertTrue($footerActions['add_note_or_article']->isHidden(), 'Add Note/Article should be hidden for closed ticket');

        $component->mountAction('reopen_ticket', ['znuny_ticket_id' => 103])
            ->setActionData(['reason' => 'Testing reopen from UI'])
            ->callMountedAction()
            ->assertNotified('Ticket Reopened')
            ->assertActionNotMounted('viewTicket') // Parent action should be closed/unmounted
            ->assertDontSee('TN103');

        $component2 = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 103])
            ->assertActionMounted('viewTicket');

        $viewActionReopened = $component2->instance()->getMountedAction();
        $record = $viewActionReopened->getRecord();
        $this->assertEquals(103, $record['TicketID'] ?? null);
        $this->assertEquals('TN103', $record['TicketNumber'] ?? null);
        $this->assertEquals('Closed Ticket', $record['Title'] ?? null);
        $this->assertEquals('open', $record['StateType'] ?? null);
        $this->assertEquals(1, $record['ArticleCount'] ?? null);

        // Assert footer actions for REOPENED (now OPEN) ticket
        $footerActionsReopened = $viewActionReopened->getExtraModalFooterActions();
        $this->assertTrue($footerActionsReopened['reopen_ticket']->isHidden(), 'Reopen Ticket should be hidden for reopened ticket');
        $this->assertFalse($footerActionsReopened['manual_close_ticket']->isHidden(), 'Close Ticket should be visible for reopened ticket');
        $this->assertFalse($footerActionsReopened['add_note_or_article']->isHidden(), 'Add Note/Article should be visible for reopened ticket');
    }

    public function test_moved_ticket_is_resolvable_even_outside_current_filter()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 104, 'TicketNumber' => 'TN104', 'Title' => 'Test Move', 'StateType' => 'new']);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('closeTicket')->with(104, \Mockery::any())->andReturn(['success' => true]);
        $mockClient->shouldReceive('unlockTicket')->with(104)->andReturn(['success' => true]);
        $mockClient->shouldReceive('getTicket')->with(104)->andReturn([
            'TicketID' => 104,
            'TicketNumber' => 'TN104',
            'Title' => 'Test Move',
            'StateType' => 'closed',
            'Created' => now()->subDay()->toIso8601String(),
            'Changed' => now()->toIso8601String(),
        ]);
        $mockClient->shouldReceive('getTicketArticles')->with(104)->andReturn([]);
        $this->setupChangeAssignmentDependencies();
        $this->app->instance(ZnunyClient::class, $mockClient);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->set('stateTypeFilter', ['new'])
            ->assertSee('TN104')
            ->mountAction('viewTicket', ['znuny_ticket_id' => 104]);

        $component->mountAction('manual_close_ticket', ['znuny_ticket_id' => 104])
            ->setActionData(['reason' => 'Closing ticket'])
            ->callMountedAction()
            ->assertNotified('Ticket Closed')
            ->assertDontSee('TN104'); // Missing from list due to filter

        $component2 = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 104]) // But modal should still open
            ->assertActionMounted('viewTicket');

        $viewActionMoved = $component2->instance()->getMountedAction();
        $record = $viewActionMoved->getRecord();
        $this->assertEquals(104, $record['TicketID'] ?? null);
        $this->assertEquals('TN104', $record['TicketNumber'] ?? null);
        $this->assertEquals('Test Move', $record['Title'] ?? null);
    }

    public function test_unlocked_ticket_shows_take_action()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 201, 'TicketNumber' => 'TN201', 'Lock' => 'unlock', 'StateType' => 'open']);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 201])
            ->assertActionMounted('viewTicket');

        $viewAction = $component->instance()->getMountedAction();
        $footerActions = $viewAction->getExtraModalFooterActions();

        $this->assertArrayHasKey('take_or_release_ticket', $footerActions);
        $this->assertFalse($footerActions['take_or_release_ticket']->isHidden());
        $this->assertEquals('Take', $footerActions['take_or_release_ticket']->getLabel());
    }

    public function test_locked_ticket_shows_release_action()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 202, 'TicketNumber' => 'TN202', 'Lock' => 'lock', 'StateType' => 'open']);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 202])
            ->assertActionMounted('viewTicket');

        $viewAction = $component->instance()->getMountedAction();
        $footerActions = $viewAction->getExtraModalFooterActions();

        $this->assertArrayHasKey('take_or_release_ticket', $footerActions);
        $this->assertFalse($footerActions['take_or_release_ticket']->isHidden());
        $this->assertEquals('Release', $footerActions['take_or_release_ticket']->getLabel());
    }

    public function test_take_ticket_action_locks_and_refreshes_cache()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 203, 'TicketNumber' => 'TN203', 'Lock' => 'unlock', 'StateType' => 'open']);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('lockTicket')->with(203)->andReturn(['success' => true]);
        // Refresh ticket calls
        $mockClient->shouldReceive('getTicket')->with(203)->andReturn([
            'TicketID' => 203,
            'TicketNumber' => 'TN203',
            'Lock' => 'lock',
            'StateType' => 'open',
            'Created' => now()->subDay()->toIso8601String(),
            'Changed' => now()->toIso8601String(),
        ]);
        $mockClient->shouldReceive('getTicketArticles')->with(203)->andReturn([]);
        $this->setupChangeAssignmentDependencies();
        $this->app->instance(ZnunyClient::class, $mockClient);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 203]);

        $component->mountAction('take_or_release_ticket', ['znuny_ticket_id' => 203])
            ->callMountedAction()
            ->assertNotified('Ticket Taken');

        $component2 = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 203]);

        $viewAction = $component2->instance()->getMountedAction();
        $footerActions = $viewAction->getExtraModalFooterActions();
        $this->assertEquals('Release', $footerActions['take_or_release_ticket']->getLabel());
    }

    public function test_release_ticket_action_unlocks_and_refreshes_cache()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 204, 'TicketNumber' => 'TN204', 'Lock' => 'lock', 'StateType' => 'open']);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('unlockTicket')->with(204)->andReturn(['success' => true]);
        // Refresh ticket calls
        $mockClient->shouldReceive('getTicket')->with(204)->andReturn([
            'TicketID' => 204,
            'TicketNumber' => 'TN204',
            'Lock' => 'unlock',
            'StateType' => 'open',
            'Created' => now()->subDay()->toIso8601String(),
            'Changed' => now()->toIso8601String(),
        ]);
        $mockClient->shouldReceive('getTicketArticles')->with(204)->andReturn([]);
        $this->setupChangeAssignmentDependencies();
        $this->app->instance(ZnunyClient::class, $mockClient);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 204]);

        $component->mountAction('take_or_release_ticket', ['znuny_ticket_id' => 204])
            ->callMountedAction()
            ->assertNotified('Ticket Released');

        $component2 = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 204]);

        $viewAction = $component2->instance()->getMountedAction();
        $footerActions = $viewAction->getExtraModalFooterActions();
        $this->assertEquals('Take', $footerActions['take_or_release_ticket']->getLabel());
    }

    public function test_take_ticket_action_handles_api_failure()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->seedTicket(['TicketID' => 205, 'TicketNumber' => 'TN205', 'Lock' => 'unlock', 'StateType' => 'open']);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('lockTicket')->with(205)->andReturn(['success' => false, 'errors' => ['Znuny is offline']]);
        $this->setupChangeAssignmentDependencies();
        $this->app->instance(ZnunyClient::class, $mockClient);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 205]);

        $component->mountAction('take_or_release_ticket', ['znuny_ticket_id' => 205])
            ->callMountedAction()
            ->assertNotified('Take Failed');
    }

    public function test_change_assignment_action_validates_and_executes_then_refreshes()
    {
        $user = User::factory()->create(['role' => 'operator']);

        Http::fake([
            'https://example.invalid/api/*' => Http::response(['SessionID' => 'fake'], 200),
        ]);

        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        $this->seedTicket(['TicketID' => 305, 'TicketNumber' => 'TN305', 'StateType' => 'open']);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('getTicket')->with(305)->andReturn([
            'TicketID' => 305,
            'ArticleCount' => 1,
            'LastArticleID' => 1,
        ]);
        $mockClient->shouldReceive('validateTicketMoveAssign')->once()->andReturn(['Valid' => 1]);
        $mockClient->shouldReceive('moveAssignTicket')->once()->andReturn(['Success' => 1]);
        $this->setupChangeAssignmentDependencies();
        $this->app->instance(ZnunyClient::class, $mockClient);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 305]);

        $component->mountAction('change_assignment', ['znuny_ticket_id' => 305])
            ->setActionData([
                'target_queue' => 'Different Queue',
                'target_owner' => 'new.owner',
                'target_customer' => 'customer.1',
                'note' => 'Changing assignment',
            ])
            ->callMountedAction()
            ->assertNotified(__('znuny_ticket_workspace.management_actions.assignment_changed'));
    }

    public function test_change_assignment_action_button_label_and_footer_order()
    {
        $user = User::factory()->create(['role' => 'operator']);

        Http::fake([
            'https://example.invalid/api/*' => Http::response(['SessionID' => 'fake'], 200),
        ]);
        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        $this->seedTicket(['TicketID' => 306, 'TicketNumber' => 'TN306', 'StateType' => 'open']);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $this->setupChangeAssignmentDependencies();
        $this->app->instance(ZnunyClient::class, $mockClient);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 306]);

        $viewAction = $component->instance()->getMountedAction();
        $footerActions = array_values($viewAction->getExtraModalFooterActions());

        $this->assertEquals('manual_close_ticket', $footerActions[0]->getName());
        $this->assertEquals('change_assignment', $footerActions[2]->getName());
        $this->assertEquals('add_note_or_article', $footerActions[3]->getName());
        $this->assertEquals('take_or_release_ticket', $footerActions[4]->getName());
        $this->assertEquals('open_ticket', $footerActions[5]->getName());

        $this->assertEquals('Change', $footerActions[2]->getLabel());
        $this->assertEquals('Change Assignment', $footerActions[2]->getModalHeading());
    }

    public function test_change_assignment_owner_change_with_empty_note_sends_fallback_and_no_article()
    {
        $user = User::factory()->create(['role' => 'operator']);

        Http::fake([
            'https://example.invalid/api/*' => Http::response(['SessionID' => 'fake'], 200),
        ]);
        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        $this->seedTicket(['TicketID' => 307, 'TicketNumber' => 'TN307', 'StateType' => 'open', 'Owner' => 'old.owner']);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('getTicket')->with(307)->andReturn([
            'TicketID' => 307,
            'ArticleCount' => 1,
            'LastArticleID' => 1,
        ]);
        $mockClient->shouldReceive('validateTicketMoveAssign')->with(\Mockery::on(function ($payload) {
            return $payload['Note'] === 'Assignment changed from integration UI.';
        }))->once()->andReturn(['Valid' => 1]);
        $mockClient->shouldReceive('moveAssignTicket')->once()->andReturn(['Success' => 1]);
        $this->setupChangeAssignmentDependencies();
        $this->app->instance(ZnunyClient::class, $mockClient);

        $articleServiceMock = \Mockery::mock(ZnunyTicketArticleWriteService::class);
        $articleServiceMock->shouldReceive('createTicketArticle')->never();
        $this->app->instance(ZnunyTicketArticleWriteService::class, $articleServiceMock);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 307]);

        $component->mountAction('change_assignment', ['znuny_ticket_id' => 307])
            ->setActionData([
                'target_queue' => 'old.queue',
                'target_owner' => 'new.owner',
                'target_customer' => 'customer.1',
                'note' => null,
            ])
            ->callMountedAction()
            ->assertNotified(__('znuny_ticket_workspace.management_actions.assignment_changed'));
    }

    public function test_change_assignment_owner_change_with_note_sends_note_and_creates_article()
    {
        $user = User::factory()->create(['role' => 'operator']);

        Http::fake([
            'https://example.invalid/api/*' => Http::response(['SessionID' => 'fake'], 200),
        ]);
        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        $this->seedTicket(['TicketID' => 307, 'TicketNumber' => 'TN307', 'StateType' => 'open', 'Owner' => 'old.owner']);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('getTicket')->with(307)->andReturn([
            'TicketID' => 307,
            'ArticleCount' => 1,
            'LastArticleID' => 1,
        ]);
        $mockClient->shouldReceive('validateTicketMoveAssign')->with(\Mockery::on(function ($payload) {
            return $payload['Note'] === 'My new note';
        }))->once()->andReturn(['Valid' => 1]);
        $mockClient->shouldReceive('moveAssignTicket')->once()->andReturn(['Success' => 1]);
        $this->setupChangeAssignmentDependencies();
        $this->app->instance(ZnunyClient::class, $mockClient);

        $articleServiceMock = \Mockery::mock(ZnunyTicketArticleWriteService::class);
        $articleServiceMock->shouldReceive('createTicketArticle')->once()->with('307', 'Assignment changed', 'My new note', false)->andReturn(['success' => true]);
        $this->app->instance(ZnunyTicketArticleWriteService::class, $articleServiceMock);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 307]);

        $component->mountAction('change_assignment', ['znuny_ticket_id' => 307])
            ->setActionData([
                'target_queue' => 'old.queue',
                'target_owner' => 'new.owner',
                'target_customer' => 'customer.1',
                'note' => 'My new note',
            ])
            ->callMountedAction()
            ->assertNotified(__('znuny_ticket_workspace.management_actions.assignment_changed'));
    }

    public function test_change_assignment_queue_change_with_note_creates_article()
    {
        $user = User::factory()->create(['role' => 'operator']);

        Http::fake([
            'https://example.invalid/api/*' => Http::response(['SessionID' => 'fake'], 200),
        ]);
        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        $this->seedTicket(['TicketID' => 307, 'TicketNumber' => 'TN307', 'StateType' => 'open', 'Owner' => 'old.owner', 'Queue' => 'old.queue']);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('getTicket')->with(307)->andReturn([
            'TicketID' => 307,
            'ArticleCount' => 1,
            'LastArticleID' => 1,
        ]);
        $mockClient->shouldReceive('validateTicketMoveAssign')->with(\Mockery::on(function ($payload) {
            return ! isset($payload['Note']);
        }))->once()->andReturn(['Valid' => 1]);
        $mockClient->shouldReceive('moveAssignTicket')->once()->andReturn(['Success' => 1]);
        $this->setupChangeAssignmentDependencies();
        $this->app->instance(ZnunyClient::class, $mockClient);

        $articleServiceMock = \Mockery::mock(ZnunyTicketArticleWriteService::class);
        $articleServiceMock->shouldReceive('createTicketArticle')->once()->with('307', 'Assignment changed', 'Queue changed note', false)->andReturn(['success' => true]);
        $this->app->instance(ZnunyTicketArticleWriteService::class, $articleServiceMock);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 307]);

        $component->mountAction('change_assignment', ['znuny_ticket_id' => 307])
            ->setActionData([
                'target_queue' => 'new.queue',
                'target_owner' => 'old.owner',
                'note' => 'Queue changed note',
            ])
            ->callMountedAction()
            ->assertNotified(__('znuny_ticket_workspace.management_actions.assignment_changed'));
    }

    public function test_change_assignment_validation_failure_no_article()
    {
        $user = User::factory()->create(['role' => 'operator']);

        Http::fake([
            'https://example.invalid/api/*' => Http::response(['SessionID' => 'fake'], 200),
        ]);
        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        $this->seedTicket(['TicketID' => 308, 'TicketNumber' => 'TN308', 'StateType' => 'open', 'Queue' => 'old.queue', 'Owner' => 'old.owner']);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('validateTicketMoveAssign')->once()->andReturn(['Valid' => 0, 'Errors' => ['Invalid Queue']]);
        $this->setupChangeAssignmentDependencies();
        $this->app->instance(ZnunyClient::class, $mockClient);

        $articleServiceMock = \Mockery::mock(ZnunyTicketArticleWriteService::class);
        $articleServiceMock->shouldReceive('createTicketArticle')->never();
        $this->app->instance(ZnunyTicketArticleWriteService::class, $articleServiceMock);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 308]);

        $component->mountAction('change_assignment', ['znuny_ticket_id' => 308])
            ->setActionData([
                'target_queue' => 'new.queue',
                'target_owner' => 'old.owner',
                'note' => 'Should not create article',
            ])
            ->callMountedAction()
            ->assertNotified(__('znuny_ticket_workspace.management_actions.validation_failed'));
    }

    public function test_change_assignment_execution_failure_no_article()
    {
        $user = User::factory()->create(['role' => 'operator']);

        Http::fake([
            'https://example.invalid/api/*' => Http::response(['SessionID' => 'fake'], 200),
        ]);
        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        $this->seedTicket(['TicketID' => 308, 'TicketNumber' => 'TN308', 'StateType' => 'open', 'Queue' => 'old.queue', 'Owner' => 'old.owner']);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('validateTicketMoveAssign')->once()->andReturn(['Valid' => 1]);
        $mockClient->shouldReceive('moveAssignTicket')->once()->andReturn(['Success' => 0, 'Errors' => ['System error']]);
        $this->setupChangeAssignmentDependencies();
        $this->app->instance(ZnunyClient::class, $mockClient);

        $articleServiceMock = \Mockery::mock(ZnunyTicketArticleWriteService::class);
        $articleServiceMock->shouldReceive('createTicketArticle')->never();
        $this->app->instance(ZnunyTicketArticleWriteService::class, $articleServiceMock);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 308]);

        $component->mountAction('change_assignment', ['znuny_ticket_id' => 308])
            ->setActionData([
                'target_queue' => 'new.queue',
                'target_owner' => 'old.owner',
                'note' => 'Should not create article',
            ])
            ->callMountedAction()
            ->assertNotified(__('znuny_ticket_workspace.management_actions.update_failed'));
    }

    public function test_change_assignment_action_blocks_empty_queue_or_owner()
    {
        $user = User::factory()->create(['role' => 'operator']);

        Http::fake([
            'https://example.invalid/api/*' => Http::response(['SessionID' => 'fake'], 200),
        ]);
        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        $this->seedTicket(['TicketID' => 308, 'TicketNumber' => 'TN308', 'StateType' => 'open', 'Queue' => 'old.queue', 'Owner' => 'old.owner']);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $this->setupChangeAssignmentDependencies();
        $this->app->instance(ZnunyClient::class, $mockClient);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 308]);

        $component->mountAction('change_assignment', ['znuny_ticket_id' => 308])
            ->setActionData([
                'target_queue' => 'new.queue',
                'target_owner' => '', // This is empty
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['target_owner' => 'required']);
    }

    public function test_change_assignment_action_shows_warning_when_refresh_fails()
    {
        $user = User::factory()->create(['role' => 'operator']);

        Http::fake([
            'https://example.invalid/api/*' => Http::response(['SessionID' => 'fake'], 200),
        ]);
        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        $this->seedTicket(['TicketID' => 309, 'TicketNumber' => 'TN309', 'StateType' => 'open', 'Owner' => 'old.owner']);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('validateTicketMoveAssign')->once()->andReturn(['Valid' => 1]);
        $mockClient->shouldReceive('moveAssignTicket')->once()->andReturn(['Success' => 1]);
        $mockClient->shouldReceive('getTicket')->andThrow(new \Exception('API offline'));
        $this->setupChangeAssignmentDependencies();
        $this->app->instance(ZnunyClient::class, $mockClient);

        $articleServiceMock = \Mockery::mock(ZnunyTicketArticleWriteService::class);
        $articleServiceMock->shouldReceive('createTicketArticle')->once()->with('309', 'Assignment changed', 'refresh fail test', false)->andReturn(['success' => true]);
        $this->app->instance(ZnunyTicketArticleWriteService::class, $articleServiceMock);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 309]);

        $component->mountAction('change_assignment', ['znuny_ticket_id' => 309])
            ->setActionData([
                'target_queue' => 'Different Queue',
                'target_owner' => 'new.owner',
                'note' => 'refresh fail test',
            ])
            ->callMountedAction()
            ->assertNotified(__('znuny_ticket_workspace.management_actions.assignment_changed_refresh_failed'));
    }

    public function test_change_assignment_filters_excluded_agents()
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->seedTicket([
            'TicketID' => 310,
            'TicketNumber' => 'TN310',
            'StateType' => 'open',
            'Queue' => 'Q1',
            'Owner' => 'old.owner',
        ]);

        Setting::updateOrCreate([
            'key' => 'znuny_agent_exclude_logins',
        ], [
            'type' => 'string',
            'value' => 'excluded.owner',
        ]);

        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate([
            'key' => 'znuny_password',
        ], [
            'value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'),
            'type' => 'string',
        ]);

        Http::fake([
            'https://example.invalid/api/*' => Http::response(['SessionID' => 'fake'], 200),
        ]);

        $mockClient = \Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldNotReceive('validateTicketMoveAssign');
        $mockClient->shouldNotReceive('moveAssignTicket');

        $this->setupChangeAssignmentDependencies(
            ['old.owner' => 'old.owner', 'excluded.owner' => 'excluded.owner'],
            ['Q1' => 'Q1'],
        );

        $agentMock = \Mockery::mock(ZnunyAgentService::class);
        $agentMock->shouldReceive('getAgentNameMap')
            ->andReturn([
                1 => 'old.owner',
            ])
            ->byDefault();
        $agentMock->shouldReceive('isLoginExcluded')
            ->andReturnUsing(fn ($login): bool => strcasecmp((string) $login, 'excluded.owner') === 0)
            ->byDefault();
        $this->app->instance(ZnunyAgentService::class, $agentMock);

        $this->app->instance(ZnunyClient::class, $mockClient);

        $component = Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->mountAction('viewTicket', ['znuny_ticket_id' => 310]);

        $component->mountAction('change_assignment', ['znuny_ticket_id' => 310])
            ->setActionData([
                'target_queue' => 'Q1',
                'target_owner' => 'excluded.owner',
            ])
            ->callMountedAction()
            ->assertNotified(__('znuny_ticket_workspace.management_actions.invalid_owner'));
    }

    public function test_workspace_disabled_state_shows_notice_and_hides_table()
    {
        $user = User::factory()->create(['role' => 'admin']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['value' => '0']);
        SettingsService::clearAllCaches();

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertSee(__('znuny_ticket_workspace.actions.refresh_from_znuny.notifications.disabled_title'))
            ->assertSee(__('znuny_ticket_workspace.actions.refresh_from_znuny.notifications.disabled_page_body'))
            ->assertDontSee(__('znuny_ticket_workspace.legend.title'))
            ->assertDontSeeHtml('wire:poll')
            ->assertActionHidden('refresh');
    }

    public function test_workspace_refresh_action_notifies_if_disabled()
    {
        $user = User::factory()->create(['role' => 'admin']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['value' => '0']);
        SettingsService::clearAllCaches();

        Artisan::shouldReceive('call')->never();
        Artisan::shouldReceive('output')->never();

        $closedSync = \Mockery::mock(ClosedTicketSyncService::class);
        $closedSync->shouldNotReceive('syncManual');
        $this->app->instance(ClosedTicketSyncService::class, $closedSync);

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->call('refreshFromZnuny')
            ->assertSuccessful()
            ->assertNotified(
                Notification::make()
                    ->title(__('znuny_ticket_workspace.actions.refresh_from_znuny.notifications.disabled_title'))
                    ->body(__('znuny_ticket_workspace.actions.refresh_from_znuny.notifications.disabled_body'))
                    ->warning()
            );
    }

    public function test_workspace_enabled_state_shows_normal_workspace()
    {
        $user = User::factory()->create(['role' => 'admin']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['value' => '1']);
        SettingsService::clearAllCaches();

        Livewire::actingAs($user)
            ->test(ZnunyTicketWorkspace::class)
            ->assertSuccessful()
            ->assertDontSee(__('znuny_ticket_workspace.actions.refresh_from_znuny.notifications.disabled_title'))
            ->assertSee(__('znuny_ticket_workspace.legend.title'))
            ->assertSeeHtml('wire:poll')
            ->assertActionVisible('refresh');
    }

    public function test_new_ticket_tracking_assigns_stars_correctly()
    {
        $userA = User::factory()->create(['role' => 'operator', 'track_new_tickets' => true, 'ticket_tracking_since' => now()->subHours(2)]);
        $userB = User::factory()->create(['role' => 'operator', 'track_new_tickets' => true, 'ticket_tracking_since' => now()->subHours(2)]);
        $userDisabled = User::factory()->create(['role' => 'operator', 'track_new_tickets' => false]);

        $this->seedTicket([
            'TicketID' => 300,
            'TicketNumber' => 'TN300',
            'Created' => now()->subHour()->toDateTimeString(), // Newer than tracking_since
            'is_linked_to_zabbix_problem' => false,
        ]);

        $this->seedTicket([
            'TicketID' => 301,
            'TicketNumber' => 'TN301',
            'Created' => now()->subHours(3)->toDateTimeString(), // Older than tracking_since
            'is_linked_to_zabbix_problem' => false,
        ]);

        $this->seedTicket([
            'TicketID' => 302,
            'TicketNumber' => 'TN302',
            'Created' => now()->toDateTimeString(),
        ]);
        \App\Models\ZabbixTicket::create([
            'znuny_ticket_id' => 302,
            'znuny_ticket_number' => 'TN302',
            'zabbix_event_id' => '123456',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'Problem 1',
        ]);

        // User A context
        $componentA = Livewire::actingAs($userA)->test(ZnunyTicketWorkspace::class);
        $dataA = $componentA->instance()->ticketData();
        $ticket300A = collect($dataA['rows'])->firstWhere('TicketID', 300);
        $this->assertTrue($ticket300A['is_new_for_user']);

        $ticket301A = collect($dataA['rows'])->firstWhere('TicketID', 301);
        $this->assertFalse($ticket301A['is_new_for_user']); // Too old

        $ticket302A = collect($dataA['rows'])->firstWhere('TicketID', 302);
        $this->assertFalse($ticket302A['is_new_for_user']); // Zabbix linked

        // User B context (should see same)
        $componentB = Livewire::actingAs($userB)->test(ZnunyTicketWorkspace::class);
        $dataB = $componentB->instance()->ticketData();
        $this->assertTrue(collect($dataB['rows'])->firstWhere('TicketID', 300)['is_new_for_user']);

        // User Disabled context (should see none)
        $componentDisabled = Livewire::actingAs($userDisabled)->test(ZnunyTicketWorkspace::class);
        $dataDisabled = $componentDisabled->instance()->ticketData();
        $this->assertFalse(collect($dataDisabled['rows'])->firstWhere('TicketID', 300)['is_new_for_user']);

        // User A opens ticket 300
        \Illuminate\Support\Facades\Auth::login($userA);
        $componentA->call('mountAction', 'viewTicket', ['znuny_ticket_id' => 300]);
        $actionA = $componentA->instance()->getMountedAction();
        if ($actionA) {
            $actionA->evaluate($actionA->getRecord(), ['arguments' => $actionA->getArguments()]);
        }

        // Re-fetch data for User A
        $dataA2 = $componentA->instance()->ticketData();
        $this->assertFalse(collect($dataA2['rows'])->firstWhere('TicketID', 300)['is_new_for_user']); // Star gone for A

        // Switch to User B to verify B's state
        \Illuminate\Support\Facades\Auth::login($userB);

        // Re-fetch data for User B
        $dataB2 = $componentB->instance()->ticketData();
        $this->assertTrue(collect($dataB2['rows'])->firstWhere('TicketID', 300)['is_new_for_user']); // Star still there for B
    }
}
