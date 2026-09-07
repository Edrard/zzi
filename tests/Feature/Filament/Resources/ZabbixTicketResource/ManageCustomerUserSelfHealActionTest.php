<?php

namespace Tests\Feature\Filament\Resources\ZabbixTicketResource;

use App\Filament\Resources\ZabbixTickets\Pages\ListZabbixTickets;
use App\Filament\Support\TicketDetailsPayload;
use App\Models\User;
use App\Models\ZabbixTicket;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyCustomerUserEditService;
use App\Services\Znuny\ZnunyCustomerUserExistenceService;
use App\Services\Znuny\ZnunyTicketCacheReconciliationService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ManageCustomerUserSelfHealActionTest extends TestCase
{
    use RefreshDatabase;

    private $lookup;

    protected function setUp(): void
    {
        parent::setUp();

        TicketDetailsPayload::clearCache();

        $this->lookup = Mockery::mock(ZnunyLookupCacheReadService::class);
        $this->lookup->shouldReceive('getCustomerCompanies')
            ->andReturn([
                'bimat' => 'Bimat',
                'real-comp' => 'Real Company',
            ])
            ->byDefault();

        $this->app->instance(ZnunyLookupCacheReadService::class, $this->lookup);
    }

    public function test_orange_not_found_opens_create_on_same_click(): void
    {
        $user = User::factory()->create(['role' => 'operator']);
        $ticket = $this->ticket(71003);

        $client = Mockery::mock(ZnunyClient::class)->makePartial();
        $client->shouldReceive('getTicket')
            ->with(71003)
            ->andReturn($this->ticketSnapshot(71003, 'orange@example.com', 'bimat', false))
            ->byDefault();
        $this->app->instance(ZnunyClient::class, $client);

        $existence = Mockery::mock(ZnunyCustomerUserExistenceService::class);
        $existence->shouldReceive('lookupDirectly')
            ->once()
            ->with('orange@example.com', 'bimat')
            ->andReturn([
                'registered' => false,
                'source' => ZnunyCustomerUserExistenceService::SOURCE_LIVE,
                'generation' => 'gen1',
            ]);
        $this->app->instance(ZnunyCustomerUserExistenceService::class, $existence);

        $reconciliation = Mockery::mock(ZnunyTicketCacheReconciliationService::class);
        $reconciliation->shouldNotReceive('reconcileKnownCustomerUserIdentity');
        $this->app->instance(ZnunyTicketCacheReconciliationService::class, $reconciliation);

        Livewire::actingAs($user)
            ->test(ListZabbixTickets::class)
            ->mountAction($this->manageCustomerUserAction($ticket))
            ->assertSchemaStateSet([
                'login' => 'orange@example.com',
                'email' => 'orange@example.com',
            ]);
    }

    public function test_orange_found_reconciles_local_identity_and_does_not_open_create(): void
    {
        $user = User::factory()->create(['role' => 'operator']);
        $ticket = $this->ticket(71002);

        $client = Mockery::mock(ZnunyClient::class)->makePartial();
        $client->shouldReceive('getTicket')
            ->with(71002)
            ->andReturn($this->ticketSnapshot(71002, 'found@example.com', 'stale-comp', false))
            ->byDefault();
        $this->app->instance(ZnunyClient::class, $client);

        $existence = Mockery::mock(ZnunyCustomerUserExistenceService::class);
        $existence->shouldReceive('lookupDirectly')
            ->once()
            ->with('found@example.com', 'stale-comp')
            ->andReturn([
                'registered' => true,
                'source' => ZnunyCustomerUserExistenceService::SOURCE_LIVE,
                'generation' => 'gen1',
                'login' => 'found@example.com',
                'customer_id' => 'real-comp',
                'status' => 'active',
            ]);
        $this->app->instance(ZnunyCustomerUserExistenceService::class, $existence);

        $reconciliation = Mockery::mock(ZnunyTicketCacheReconciliationService::class);
        $reconciliation->shouldReceive('reconcileKnownCustomerUserIdentity')
            ->once()
            ->with('found@example.com', 'found@example.com', 'real-comp', 71002);
        $this->app->instance(ZnunyTicketCacheReconciliationService::class, $reconciliation);

        Livewire::actingAs($user)
            ->test(ListZabbixTickets::class)
            ->mountAction($this->manageCustomerUserAction($ticket));
    }

    public function test_gray_found_opens_edit_with_direct_znuny_data(): void
    {
        $user = User::factory()->create(['role' => 'operator']);
        $ticket = $this->ticket(71004);

        $client = Mockery::mock(ZnunyClient::class)->makePartial();
        $client->shouldReceive('getTicket')
            ->with(71004)
            ->andReturn($this->ticketSnapshot(71004, 'gray@example.com', 'bimat', true))
            ->byDefault();
        $client->shouldReceive('getCustomerUser')
            ->once()
            ->with('gray@example.com')
            ->andReturn([
                'found' => true,
                'login' => 'gray@example.com',
                'email' => 'gray@example.com',
                'first_name' => 'Gray',
                'last_name' => 'User',
                'customer_id' => 'bimat',
                'status' => 'active',
            ]);
        $this->app->instance(ZnunyClient::class, $client);

        $existence = Mockery::mock(ZnunyCustomerUserExistenceService::class);
        $existence->shouldReceive('getActiveGeneration')->once()->andReturn('gen1');
        $existence->shouldReceive('cacheShortExistence')
            ->once()
            ->with('gen1', 'gray@example.com', true);

        $reconciliation = Mockery::mock(ZnunyTicketCacheReconciliationService::class);
        $reconciliation->shouldNotReceive('reconcileKnownCustomerUserIdentity');

        $this->app->instance(
            ZnunyCustomerUserEditService::class,
            new ZnunyCustomerUserEditService(
                $client,
                $this->lookup,
                $existence,
                $reconciliation,
            ),
        );

        Livewire::actingAs($user)
            ->test(ListZabbixTickets::class)
            ->mountAction($this->manageCustomerUserAction($ticket))
            ->assertSchemaStateSet([
                'login' => 'gray@example.com',
                'email' => 'gray@example.com',
                'first_name' => 'Gray',
                'last_name' => 'User',
                'customer_id' => 'bimat',
            ]);
    }

    public function test_gray_not_found_is_logged_and_non_destructive(): void
    {
        Log::spy();

        $user = User::factory()->create(['role' => 'operator']);
        $ticket = $this->ticket(71001);

        $client = Mockery::mock(ZnunyClient::class)->makePartial();
        $client->shouldReceive('getTicket')
            ->with(71001)
            ->andReturn($this->ticketSnapshot(71001, 'missing@example.com', 'bimat', true))
            ->byDefault();
        $client->shouldReceive('getCustomerUser')
            ->once()
            ->with('missing@example.com')
            ->andReturn(['found' => false]);
        $this->app->instance(ZnunyClient::class, $client);

        $existence = Mockery::mock(ZnunyCustomerUserExistenceService::class);
        $existence->shouldNotReceive('cacheShortExistence');

        $reconciliation = Mockery::mock(ZnunyTicketCacheReconciliationService::class);
        $reconciliation->shouldNotReceive('reconcileKnownCustomerUserIdentity');

        $this->app->instance(
            ZnunyCustomerUserEditService::class,
            new ZnunyCustomerUserEditService(
                $client,
                $this->lookup,
                $existence,
                $reconciliation,
            ),
        );

        Livewire::actingAs($user)
            ->test(ListZabbixTickets::class)
            ->mountAction($this->manageCustomerUserAction($ticket));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message, $context) => str_contains((string) $message, 'not found on direct lookup')
                && ($context['ticket_id'] ?? null) === 71001
                && ($context['login'] ?? null) === 'missing@example.com'
            );
    }

    /**
     * @return array<int, TestAction>
     */
    private function manageCustomerUserAction(ZabbixTicket $ticket): array
    {
        return [
            TestAction::make('viewTicket')->table($ticket),
            TestAction::make('manage_customer_user')->schemaComponent('customer_user'),
        ];
    }

    private function ticket(int $znunyTicketId): ZabbixTicket
    {
        return ZabbixTicket::create([
            'zabbix_event_id' => 'evt-'.$znunyTicketId,
            'zabbix_problem_name' => 'Customer user action test',
            'zabbix_host_name' => 'test-host',
            'znuny_ticket_id' => $znunyTicketId,
            'znuny_ticket_number' => (string) $znunyTicketId,
            'znuny_ticket_state_type' => 'open',
        ]);
    }

    private function ticketSnapshot(
        int $ticketId,
        string $login,
        string $customerId,
        bool $registered,
    ): array {
        return [
            'TicketID' => $ticketId,
            'TicketNumber' => (string) $ticketId,
            'StateType' => 'open',
            'Lock' => 'unlock',
            'CustomerUserID' => $login,
            'CustomerID' => $customerId,
            'customer_user_registered' => $registered,
        ];
    }
}
