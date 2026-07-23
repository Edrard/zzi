<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Pages\CreateTicket;
use App\Models\User;
use App\Services\Znuny\ZnunyCachedLookupService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyStandaloneTicketCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class CreateTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_ticket_page_can_be_rendered()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/admin/create-ticket')
            ->assertSuccessful();

        Livewire::actingAs($admin)
            ->test(CreateTicket::class)
            ->assertSuccessful()
            ->assertFormExists()
            ->assertFormFieldExists('queue')
            ->assertFormFieldExists('title')
            ->assertFormFieldExists('body');
    }

    public function test_can_submit_manual_ticket()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn([
                'Raw' => 'Raw',
            ]);
            $mock->shouldReceive('getAssignableOwnerOptionsForQueue')->andReturn([
                1 => 'John Doe <johndoe>',
            ]);
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->andReturn([
                'johndoe' => 'John Doe <johndoe>',
            ]);
            $mock->shouldReceive('resolveTemplateCandidate')->andReturn('johndoe');
            $mock->shouldReceive('getTicketStates')->andReturn([
                'open' => 'open',
            ]);
            $mock->shouldReceive('getTicketPriorities')->andReturn([
                '3 normal' => '3 normal',
            ]);
        });

        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('searchCustomerUsers')->andReturn([
                ['login' => 'johndoe', 'label' => 'John Doe <johndoe>'],
            ]);
            $mock->shouldReceive('getCustomerUser')->andReturn([
                'found' => true,
                'login' => 'johndoe',
                'label' => 'John Doe <johndoe>',
            ]);
        });

        $this->mock(ZnunyStandaloneTicketCreationService::class, function (MockInterface $mock) {
            $mock->shouldReceive('createTicket')
                ->once()
                ->with(
                    1,
                    'Raw',
                    'johndoe',
                    'Test Subject',
                    'Test Body',
                    'open',
                    '3 normal',
                    'unlock'
                )
                ->andReturn([
                    'success' => true,
                    'ticket_id' => 12345,
                    'ticket_number' => '2023010112345',
                    'errors' => [],
                    'warnings' => [],
                ]);
        });

        Livewire::actingAs($admin)
            ->test(CreateTicket::class)
            ->fillForm([
                'queue' => 'Raw',
                'owner' => 1,
                'customer_user' => 'johndoe',
                'title' => 'Test Subject',
                'body' => 'Test Body',
                'state' => 'open',
                'priority' => '3 normal',
                'lock' => 'unlock',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Ticket Created');
    }

    public function test_handles_submission_failure()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn([
                'Raw' => 'Raw',
            ]);
            $mock->shouldReceive('getAssignableOwnerOptionsForQueue')->andReturn([
                1 => 'John Doe <johndoe>',
            ]);
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->andReturn([
                'johndoe' => 'John Doe <johndoe>',
            ]);
            $mock->shouldReceive('resolveTemplateCandidate')->andReturn('johndoe');
            $mock->shouldReceive('getTicketStates')->andReturn([
                'open' => 'open',
            ]);
            $mock->shouldReceive('getTicketPriorities')->andReturn([
                '3 normal' => '3 normal',
            ]);
        });

        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('searchCustomerUsers')->andReturn([
                ['login' => 'johndoe', 'label' => 'John Doe <johndoe>'],
            ]);
            $mock->shouldReceive('getCustomerUser')->andReturn([
                'found' => true,
                'login' => 'johndoe',
                'label' => 'John Doe <johndoe>',
            ]);
        });

        $this->mock(ZnunyStandaloneTicketCreationService::class, function (MockInterface $mock) {
            $mock->shouldReceive('createTicket')
                ->once()
                ->andReturn([
                    'success' => false,
                    'ticket_id' => null,
                    'ticket_number' => null,
                    'errors' => ['Znuny validation failed', 'Queue is not valid'],
                    'warnings' => [],
                ]);
        });

        Livewire::actingAs($admin)
            ->test(CreateTicket::class)
            ->fillForm([
                'queue' => 'Raw',
                'owner' => 1,
                'customer_user' => 'johndoe',
                'title' => 'Test Subject',
                'body' => 'Test Body',
                'state' => 'open',
                'priority' => '3 normal',
                'lock' => 'lock',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Ticket Creation Failed');
    }

    public function test_queue_change_updates_owner_and_customer_user()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn([
                'Raw' => 'Raw',
                'Network' => 'Network',
            ]);
            $mock->shouldReceive('getAssignableOwnerOptionsForQueue')->with('Raw')->andReturn([
                1 => 'John Doe',
            ]);
            $mock->shouldReceive('getAssignableOwnerOptionsForQueue')->with('Network')->andReturn([
                2 => 'Jane Doe',
            ]);
            $mock->shouldReceive('getAssignableOwnerOptionsForQueue')->andReturn([]);
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->with('Raw')->andReturn([
                'johndoe' => 'John Doe <johndoe>',
            ]);
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->with('Network')->andReturn([
                'janedoe' => 'Jane Doe <janedoe>',
                'netadmin' => 'Net Admin <netadmin>',
            ]);
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->andReturn([]);
            $mock->shouldReceive('resolveTemplateCandidate')->with('Raw')->andReturn('johndoe');
            $mock->shouldReceive('resolveTemplateCandidate')->with('Network')->andReturn('janedoe');
            $mock->shouldReceive('resolveTemplateCandidate')->andReturn(null);

            $mock->shouldReceive('getTicketStates')->andReturn([]);
            $mock->shouldReceive('getTicketPriorities')->andReturn([]);
        });

        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getCustomerUser')->with('johndoe')->andReturn([
                'found' => true,
                'login' => 'johndoe',
                'label' => 'John Doe <johndoe>',
            ]);
            $mock->shouldReceive('getCustomerUser')->with('janedoe')->andReturn([
                'found' => true,
                'login' => 'janedoe',
                'label' => 'Jane Doe <janedoe>',
            ]);
        });

        Livewire::actingAs($admin)
            ->test(CreateTicket::class)
            ->fillForm([
                'queue' => 'Raw',
                'owner' => 1,
            ])
            ->set('data.queue', 'Network')
            ->assertFormSet(['owner' => null, 'customer_user' => 'janedoe']);
    }

    public function test_customer_user_search_uses_cache_when_blank_and_queue_selected()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn(['Raw' => 'Raw']);
            $mock->shouldReceive('getAssignableOwnerOptionsForQueue')->andReturn([]);
            $mock->shouldReceive('getTicketStates')->andReturn([]);
            $mock->shouldReceive('getTicketPriorities')->andReturn([]);
            $mock->shouldReceive('resolveTemplateCandidate')->andReturn(null);

            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')
                ->with('Raw')
                ->atLeast()->once()
                ->andReturn(['cached1' => 'Cached User']);
        });

        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('searchCustomerUsers');
        });

        $livewire = Livewire::actingAs($admin)
            ->test(CreateTicket::class)
            ->set('data.queue', 'Raw');

        $customerUserSelect = collect($livewire->instance()->form->getComponents())
            ->flatMap(fn ($c) => $c->getChildComponents())
            ->flatMap(fn ($c) => $c->getChildComponents())
            ->firstWhere(fn ($c) => $c->getName() === 'customer_user');

        $results = $customerUserSelect->getSearchResults('');

        $this->assertEquals(['cached1' => 'Cached User'], $results);
    }

    public function test_customer_user_search_returns_empty_when_blank_and_no_queue()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn([]);
            $mock->shouldReceive('getAssignableOwnerOptionsForQueue')->andReturn([]);
            $mock->shouldReceive('getTicketStates')->andReturn([]);
            $mock->shouldReceive('getTicketPriorities')->andReturn([]);
            $mock->shouldReceive('resolveTemplateCandidate')->andReturn(null);

            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->andReturn([]);
        });

        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('searchCustomerUsers');
        });

        $livewire = Livewire::actingAs($admin)
            ->test(CreateTicket::class)
            ->set('data.queue', null);

        $customerUserSelect = collect($livewire->instance()->form->getComponents())
            ->flatMap(fn ($c) => $c->getChildComponents())
            ->flatMap(fn ($c) => $c->getChildComponents())
            ->firstWhere(fn ($c) => $c->getName() === 'customer_user');

        $results = $customerUserSelect->getSearchResults('');

        $this->assertEquals([], $results);
    }

    public function test_customer_user_search_calls_client_when_typed()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn(['Raw' => 'Raw']);
            $mock->shouldReceive('getAssignableOwnerOptionsForQueue')->andReturn([]);
            $mock->shouldReceive('getTicketStates')->andReturn([]);
            $mock->shouldReceive('getTicketPriorities')->andReturn([]);
            $mock->shouldReceive('resolveTemplateCandidate')->andReturn(null);

            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->andReturn([]);
        });

        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('searchCustomerUsers')
                ->with('john')
                ->once()
                ->andReturn([
                    ['login' => 'johndoe', 'label' => 'John Doe <johndoe>'],
                ]);
        });

        $livewire = Livewire::actingAs($admin)
            ->test(CreateTicket::class)
            ->set('data.queue', 'Raw');

        $customerUserSelect = collect($livewire->instance()->form->getComponents())
            ->flatMap(fn ($c) => $c->getChildComponents())
            ->flatMap(fn ($c) => $c->getChildComponents())
            ->firstWhere(fn ($c) => $c->getName() === 'customer_user');

        $results = $customerUserSelect->getSearchResults('john');

        $this->assertEquals(['johndoe' => 'John Doe <johndoe>'], $results);
    }

    public function test_customer_user_options_uses_cache_and_label_resolver()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn(['Raw' => 'Raw']);
            $mock->shouldReceive('getAssignableOwnerOptionsForQueue')->andReturn([]);
            $mock->shouldReceive('getTicketStates')->andReturn([]);
            $mock->shouldReceive('getTicketPriorities')->andReturn([]);
            $mock->shouldReceive('resolveTemplateCandidate')->andReturn('unknownuser');

            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')
                ->with('Raw')
                ->andReturn(['knownuser' => 'Known User']);

            $mock->shouldReceive('getCustomerUserLabel')
                ->with('unknownuser')
                ->atLeast()->once()
                ->andReturn('Unknown User Label');
        });

        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('getCustomerUser');
        });

        $livewire = Livewire::actingAs($admin)
            ->test(CreateTicket::class)
            ->set('data.queue', 'Raw')
            ->set('data.customer_user', 'unknownuser');

        $customerUserSelect = collect($livewire->instance()->form->getComponents())
            ->flatMap(fn ($c) => $c->getChildComponents())
            ->flatMap(fn ($c) => $c->getChildComponents())
            ->firstWhere(fn ($c) => $c->getName() === 'customer_user');

        $options = $customerUserSelect->getOptions();

        $this->assertEquals([
            'knownuser' => 'Known User',
            'unknownuser' => 'Unknown User Label',
        ], $options);
    }

    public function test_customer_user_label_uses_cache_and_label_resolver()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn(['Raw' => 'Raw']);
            $mock->shouldReceive('getAssignableOwnerOptionsForQueue')->andReturn([]);
            $mock->shouldReceive('getTicketStates')->andReturn([]);
            $mock->shouldReceive('getTicketPriorities')->andReturn([]);
            $mock->shouldReceive('resolveTemplateCandidate')->andReturn(null);

            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')
                ->with('Raw')
                ->andReturn(['knownuser' => 'Known User']);

            $mock->shouldReceive('getCustomerUserLabel')
                ->with('unknownuser')
                ->andReturn('Unknown User Label');

            $mock->shouldReceive('getCustomerUserLabel')
                ->with('missinguser')
                ->andReturn(null);
        });

        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('getCustomerUser');
        });

        $livewire = Livewire::actingAs($admin)
            ->test(CreateTicket::class)
            ->set('data.queue', 'Raw');

        $customerUserSelect = collect($livewire->instance()->form->getComponents())
            ->flatMap(fn ($c) => $c->getChildComponents())
            ->flatMap(fn ($c) => $c->getChildComponents())
            ->firstWhere(fn ($c) => $c->getName() === 'customer_user');

        $reflection = new \ReflectionClass($customerUserSelect);
        $property = $reflection->getProperty('getOptionLabelUsing');
        $property->setAccessible(true);
        $getOptionLabel = $property->getValue($customerUserSelect);

        $get = fn ($key) => $key === 'queue' ? 'Raw' : null;

        $this->assertEquals('Known User', app()->call($getOptionLabel, ['value' => 'knownuser', 'get' => $get]));
        $this->assertEquals('Unknown User Label', app()->call($getOptionLabel, ['value' => 'unknownuser', 'get' => $get]));
        $this->assertEquals('missinguser', app()->call($getOptionLabel, ['value' => 'missinguser', 'get' => $get]));
    }

    public function test_operator_route_and_create_allowed()
    {
        $operator = User::factory()->create(['role' => 'operator']);

        $this->actingAs($operator)
            ->get('/admin/create-ticket')
            ->assertSuccessful();

        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn(['Raw' => 'Raw']);
            $mock->shouldReceive('getAssignableOwnerOptionsForQueue')->andReturn([1 => 'John Doe <johndoe>']);
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->andReturn(['johndoe' => 'John Doe <johndoe>']);
            $mock->shouldReceive('resolveTemplateCandidate')->andReturn('johndoe');
            $mock->shouldReceive('getTicketStates')->andReturn(['open' => 'open']);
            $mock->shouldReceive('getTicketPriorities')->andReturn(['3 normal' => '3 normal']);
        });

        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('searchCustomerUsers')->andReturn([]);
            $mock->shouldReceive('getCustomerUser')->andReturn(['found' => true, 'login' => 'johndoe', 'label' => 'John Doe <johndoe>']);
        });

        $this->mock(ZnunyStandaloneTicketCreationService::class, function (MockInterface $mock) {
            $mock->shouldReceive('createTicket')->once()->andReturn([
                'success' => true,
                'ticket_id' => 12345,
                'ticket_number' => '2023010112345',
                'errors' => [],
                'warnings' => [],
            ]);
        });

        Livewire::actingAs($operator)
            ->test(CreateTicket::class)
            ->fillForm([
                'queue' => 'Raw',
                'owner' => 1,
                'customer_user' => 'johndoe',
                'title' => 'Test Subject',
                'body' => 'Test Body',
                'state' => 'open',
                'priority' => '3 normal',
                'lock' => 'unlock',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Ticket Created');
    }

    public function test_viewer_route_and_create_forbidden()
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)
            ->get('/admin/create-ticket')
            ->assertForbidden();

        $this->mock(ZnunyStandaloneTicketCreationService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('createTicket');
        });

        Livewire::actingAs($viewer)
            ->test(CreateTicket::class)
            ->assertForbidden();
    }

    public function test_viewer_direct_method_create_forbidden()
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer);

        $this->mock(ZnunyStandaloneTicketCreationService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('createTicket');
        });

        $page = new CreateTicket();

        try {
            $page->create(app(ZnunyStandaloneTicketCreationService::class));
            $this->fail('Expected 403 exception');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }
    }

    public function test_inactive_admin_cannot_administer()
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $this->assertFalse($admin->canAdministerApplication());
    }

    public function test_inactive_admin_cannot_manage_tickets()
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $this->assertFalse($admin->canManageZnunyTickets());
    }

    public function test_inactive_operator_cannot_manage_tickets()
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => false]);
        $this->assertFalse($operator->canManageZnunyTickets());
    }
}
