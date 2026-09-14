<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Pages\CreateTicket;
use App\Filament\Pages\ZnunyTicketWorkspace;
use App\Models\User;
use App\Services\Znuny\ZnunyCachedLookupService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyInlineImagePayloadService;
use App\Services\Znuny\ZnunyStandaloneTicketCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CreateTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_ticket_page_can_be_rendered()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
            $mock->shouldReceive('getTicketStates')->andReturn(['new' => 'new'])->byDefault();
            $mock->shouldReceive('getTicketPriorities')->andReturn(['3 normal' => '3 normal'])->byDefault();
        });

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
            $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn([
                'Raw' => 'Raw',
            ]);
            $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->andReturn([
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
                    '<p>Test Body</p>',
                    'open',
                    '3 normal',
                    'unlock',
                    [],
                    'text/html; charset=utf-8'
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
            ->assertNoRedirect()
            ->assertNotified('Ticket Created');
    }

    public function test_handles_submission_failure()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn([
                'Raw' => 'Raw',
            ]);
            $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->andReturn([
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
            $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn([
                'Raw' => 'Raw',
                'Network' => 'Network',
            ]);
            $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->with('Raw')->andReturn([
                1 => 'John Doe',
            ]);
            $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->with('Network')->andReturn([
                2 => 'Jane Doe',
            ]);
            $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->andReturn([]);
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
            $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn(['Raw' => 'Raw']);
            $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->andReturn([]);
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
            $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn([]);
            $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->andReturn([]);
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

    public function test_customer_user_search_uses_lookup_service_when_typed()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn(['Raw' => 'Raw']);
            $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->andReturn([]);
            $mock->shouldReceive('getTicketStates')->andReturn([]);
            $mock->shouldReceive('getTicketPriorities')->andReturn([]);
            $mock->shouldReceive('resolveTemplateCandidate')->andReturn(null);

            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->andReturn([]);
            $mock->shouldReceive('searchCustomerUserOptions')
                ->with('john')
                ->once()
                ->andReturn([
                    'johndoe' => 'John Doe <johndoe>',
                ]);
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

        $results = $customerUserSelect->getSearchResults('john');

        $this->assertEquals(['johndoe' => 'John Doe <johndoe>'], $results);
    }

    public function test_customer_user_options_uses_cache_and_label_resolver()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn(['Raw' => 'Raw']);
            $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->andReturn([]);
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
            $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn(['Raw' => 'Raw']);
            $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->andReturn([]);
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
            $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn(['Raw' => 'Raw']);
            $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->andReturn([1 => 'John Doe <johndoe>']);
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

        $page = new CreateTicket;

        try {
            $page->create(app(ZnunyStandaloneTicketCreationService::class), app(ZnunyInlineImagePayloadService::class));
            $this->fail('Expected 403 exception');
        } catch (HttpException $e) {
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

    public function test_both_actions_are_rendered_and_old_submit_label_is_not_used()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
            $mock->shouldReceive('getTicketStates')->andReturn(['new' => 'new'])->byDefault();
            $mock->shouldReceive('getTicketPriorities')->andReturn(['3 normal' => '3 normal'])->byDefault();
        });

        // Test in UK locale
        app()->setLocale('uk');

        $ukComponent = Livewire::actingAs($admin)->test(CreateTicket::class);
        $ukComponent->assertSuccessful();

        $ukHtml = $ukComponent->html();
        $this->assertStringContainsString('Створити і залишитися', $ukHtml);
        $this->assertStringContainsString('Створити', $ukHtml);

        // Form submit action area must contain new labels and not the old submit label
        preg_match('/<form[^>]*>(.*?)<\/form>/s', $ukHtml, $ukFormMatches);
        $this->assertNotEmpty($ukFormMatches);
        $this->assertStringContainsString('Створити і залишитися', $ukFormMatches[1]);
        $this->assertStringContainsString('Створити', $ukFormMatches[1]);
        $this->assertStringNotContainsString('Створити звернення', $ukFormMatches[1]);

        // Right-alignment of actions container (applies flex-row-reverse in Filament)
        $this->assertStringContainsString('fi-align-end', $ukFormMatches[1]);

        // Secondary button has native outlined/transparent style
        preg_match('/<button\b[^>]*wire:click="createAndStay"[^>]*>/s', $ukFormMatches[1], $stayButtonMatch);
        $this->assertNotEmpty($stayButtonMatch);
        $this->assertStringContainsString('fi-outlined', $stayButtonMatch[0]);

        // Primary button does not have outlined style
        preg_match('/<button\b[^>]*type="submit"[^>]*>/s', $ukFormMatches[1], $submitButtonMatch);
        $this->assertNotEmpty($submitButtonMatch);
        $this->assertStringNotContainsString('fi-outlined', $submitButtonMatch[0]);

        // In the DOM, primary submit button precedes secondary button so that under
        // Filament's fi-align-end (flex-row-reverse), the primary button renders as the RIGHTMOST button
        // and the secondary button renders immediately to its left.
        $submitPos = strpos($ukFormMatches[1], 'type="submit"');
        $stayPos = strpos($ukFormMatches[1], 'wire:click="createAndStay"');
        $this->assertTrue($submitPos !== false && $stayPos !== false && $submitPos < $stayPos);

        // Test in EN locale
        app()->setLocale('en');

        $enComponent = Livewire::actingAs($admin)->test(CreateTicket::class);
        $enComponent->assertSuccessful();

        $enHtml = $enComponent->html();
        $this->assertStringContainsString('Create and stay', $enHtml);
        $this->assertStringContainsString('Create', $enHtml);

        preg_match('/<form[^>]*>(.*?)<\/form>/s', $enHtml, $enFormMatches);
        $this->assertNotEmpty($enFormMatches);
        $this->assertStringContainsString('Create and stay', $enFormMatches[1]);
        $this->assertStringContainsString('Create', $enFormMatches[1]);
        $this->assertStringNotContainsString('Create ticket', $enFormMatches[1]);

        $this->assertStringContainsString('fi-align-end', $enFormMatches[1]);
        $submitPosEn = strpos($enFormMatches[1], 'type="submit"');
        $stayPosEn = strpos($enFormMatches[1], 'wire:click="createAndStay"');
        $this->assertTrue($submitPosEn !== false && $stayPosEn !== false && $submitPosEn < $stayPosEn);
    }

    public function test_create_and_stay_creates_ticket_and_does_not_redirect()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn(['Raw' => 'Raw']);
            $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->andReturn([1 => 'John Doe <johndoe>']);
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->andReturn(['johndoe' => 'John Doe <johndoe>']);
            $mock->shouldReceive('resolveTemplateCandidate')->andReturn('johndoe');
            $mock->shouldReceive('getTicketStates')->andReturn(['open' => 'open']);
            $mock->shouldReceive('getTicketPriorities')->andReturn(['3 normal' => '3 normal']);
        });

        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('searchCustomerUsers')->andReturn([['login' => 'johndoe', 'label' => 'John Doe <johndoe>']]);
            $mock->shouldReceive('getCustomerUser')->andReturn(['found' => true, 'login' => 'johndoe', 'label' => 'John Doe <johndoe>']);
        });

        $this->mock(ZnunyStandaloneTicketCreationService::class, function (MockInterface $mock) {
            $mock->shouldReceive('createTicket')
                ->once()
                ->with(
                    1,
                    'Raw',
                    'johndoe',
                    'Test Subject',
                    '<p>Test Body</p>',
                    'open',
                    '3 normal',
                    'unlock',
                    [],
                    'text/html; charset=utf-8'
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
            ->call('createAndStay')
            ->assertHasNoFormErrors()
            ->assertNoRedirect()
            ->assertNotified('Ticket Created');
    }

    public function test_create_and_redirect_creates_ticket_and_redirects_to_workspace()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn(['Raw' => 'Raw']);
            $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->andReturn([1 => 'John Doe <johndoe>']);
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->andReturn(['johndoe' => 'John Doe <johndoe>']);
            $mock->shouldReceive('resolveTemplateCandidate')->andReturn('johndoe');
            $mock->shouldReceive('getTicketStates')->andReturn(['open' => 'open']);
            $mock->shouldReceive('getTicketPriorities')->andReturn(['3 normal' => '3 normal']);
        });

        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('searchCustomerUsers')->andReturn([['login' => 'johndoe', 'label' => 'John Doe <johndoe>']]);
            $mock->shouldReceive('getCustomerUser')->andReturn(['found' => true, 'login' => 'johndoe', 'label' => 'John Doe <johndoe>']);
        });

        $this->mock(ZnunyStandaloneTicketCreationService::class, function (MockInterface $mock) {
            $mock->shouldReceive('createTicket')
                ->once()
                ->with(
                    1,
                    'Raw',
                    'johndoe',
                    'Test Subject',
                    '<p>Test Body</p>',
                    'open',
                    '3 normal',
                    'unlock',
                    [],
                    'text/html; charset=utf-8'
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
            ->call('createAndRedirect')
            ->assertHasNoFormErrors()
            ->assertRedirect(ZnunyTicketWorkspace::getUrl())
            ->assertNotified('Ticket Created');
    }

    public function test_failed_creation_or_validation_does_not_redirect_to_workspace()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn(['Raw' => 'Raw']);
            $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->andReturn([1 => 'John Doe <johndoe>']);
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->andReturn(['johndoe' => 'John Doe <johndoe>']);
            $mock->shouldReceive('resolveTemplateCandidate')->andReturn('johndoe');
            $mock->shouldReceive('getTicketStates')->andReturn(['open' => 'open']);
            $mock->shouldReceive('getTicketPriorities')->andReturn(['3 normal' => '3 normal']);
        });

        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('searchCustomerUsers')->andReturn([['login' => 'johndoe', 'label' => 'John Doe <johndoe>']]);
            $mock->shouldReceive('getCustomerUser')->andReturn(['found' => true, 'login' => 'johndoe', 'label' => 'John Doe <johndoe>']);
        });

        // Validation failure case
        $this->mock(ZnunyStandaloneTicketCreationService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('createTicket');
        });

        Livewire::actingAs($admin)
            ->test(CreateTicket::class)
            ->fillForm([
                'queue' => null,
            ])
            ->call('createAndRedirect')
            ->assertHasFormErrors(['queue'])
            ->assertNoRedirect();

        // API failure case
        $this->mock(ZnunyStandaloneTicketCreationService::class, function (MockInterface $mock) {
            $mock->shouldReceive('createTicket')
                ->once()
                ->andReturn([
                    'success' => false,
                    'ticket_id' => null,
                    'ticket_number' => null,
                    'errors' => ['Znuny creation failed'],
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
            ->call('createAndRedirect')
            ->assertHasNoFormErrors()
            ->assertNoRedirect()
            ->assertNotified('Ticket Creation Failed');
    }

    public function test_no_duplicate_ticket_creation_with_is_creating_guard()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(ZnunyStandaloneTicketCreationService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('createTicket');
        });

        $component = Livewire::actingAs($admin)->test(CreateTicket::class);
        $component->instance()->isCreating = true;
        $component->call('create');
    }

    public function test_action_method_signatures_do_not_contain_union_types_or_string_redirect_parsers()
    {
        $reflection = new \ReflectionClass(CreateTicket::class);

        // create()
        $create = $reflection->getMethod('create');
        $this->assertTrue($create->isPublic());
        $this->assertSame('void', (string) $create->getReturnType());
        $createParams = $create->getParameters();
        $this->assertCount(2, $createParams);
        $this->assertSame(ZnunyStandaloneTicketCreationService::class, $createParams[0]->getType()?->getName());
        $this->assertSame(ZnunyInlineImagePayloadService::class, $createParams[1]->getType()?->getName());

        // createAndStay()
        $createAndStay = $reflection->getMethod('createAndStay');
        $this->assertTrue($createAndStay->isPublic());
        $this->assertSame('void', (string) $createAndStay->getReturnType());
        $stayParams = $createAndStay->getParameters();
        $this->assertCount(2, $stayParams);
        $this->assertSame(ZnunyStandaloneTicketCreationService::class, $stayParams[0]->getType()?->getName());
        $this->assertSame(ZnunyInlineImagePayloadService::class, $stayParams[1]->getType()?->getName());

        // createAndRedirect()
        $createAndRedirect = $reflection->getMethod('createAndRedirect');
        $this->assertTrue($createAndRedirect->isPublic());
        $this->assertSame('void', (string) $createAndRedirect->getReturnType());
        $redirectParams = $createAndRedirect->getParameters();
        $this->assertCount(2, $redirectParams);
        $this->assertSame(ZnunyStandaloneTicketCreationService::class, $redirectParams[0]->getType()?->getName());
        $this->assertSame(ZnunyInlineImagePayloadService::class, $redirectParams[1]->getType()?->getName());

        // performCreate()
        $performCreate = $reflection->getMethod('performCreate');
        $this->assertTrue($performCreate->isProtected());
        $this->assertSame('void', (string) $performCreate->getReturnType());
        $performParams = $performCreate->getParameters();
        $this->assertCount(3, $performParams);
        $this->assertSame(ZnunyStandaloneTicketCreationService::class, $performParams[0]->getType()?->getName());
        $this->assertSame(ZnunyInlineImagePayloadService::class, $performParams[1]->getType()?->getName());
        $this->assertSame('bool', (string) $performParams[2]->getType()?->getName());
    }
}
