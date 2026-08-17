<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Pages\CreateTicket;
use App\Models\User;
use App\Services\Znuny\ZnunyCachedLookupService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyStandaloneTicketCreationService;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class CreateTicketLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_translation_files_have_parity()
    {
        $en = Lang::get('create_ticket', [], 'en');
        $uk = Lang::get('create_ticket', [], 'uk');

        $this->assertEquals(
            array_keys(Arr::dot($en)),
            array_keys(Arr::dot($uk)),
            'English and Ukrainian translation files must have exactly the same nested keys.'
        );
    }

    public function test_page_is_localized_in_english()
    {
        $originalLocale = app()->getLocale();
        try {
            app()->setLocale('en');

            $this->assertEquals('Create Znuny ticket', __('create_ticket.title'));
            $this->assertEquals('Create ticket', __('create_ticket.navigation_label'));

            $admin = User::factory()->create(['role' => 'admin']);

            $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
                $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
                $mock->shouldReceive('getTicketStates')->andReturn(['new' => 'new']);
                $mock->shouldReceive('getTicketPriorities')->andReturn(['3 normal' => '3 normal']);
            });

            $component = Livewire::actingAs($admin)->test(CreateTicket::class);

            $this->assertEquals('Create Znuny ticket', $component->instance()->getTitle());

            $components = collect($component->instance()->form->getComponents());

            $sections = [];
            $search = function ($comps) use (&$search, &$sections) {
                foreach ($comps as $c) {
                    if ($c instanceof Section) {
                        $sections[$c->getHeading()] = $c;
                    }
                    if (method_exists($c, 'getChildComponents')) {
                        $search($c->getChildComponents());
                    }
                }
            };
            $search($components);

            $this->assertArrayHasKey('Ticket details', $sections);
            $this->assertArrayHasKey('Advanced ticket options', $sections);

            $fields = collect($component->instance()->form->getFlatComponents());

            $queueField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'queue');
            $ownerField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'owner');
            $customerUserField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'customer_user');
            $titleField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'title');
            $bodyField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'body');
            $priorityField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'priority');
            $stateField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'state');
            $lockField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'lock');

            $this->assertEquals('Queue', $queueField->getLabel());
            $this->assertEquals('Owner', $ownerField->getLabel());
            $this->assertEquals('Customer user', $customerUserField->getLabel());
            $this->assertEquals('Title', $titleField->getLabel());
            $this->assertEquals('Article body', $bodyField->getLabel());
            $this->assertEquals('Priority', $priorityField->getLabel());
            $this->assertEquals('State', $stateField->getLabel());
            $this->assertEquals('Lock', $lockField->getLabel());

            $this->assertEquals('No options available.', $queueField->getNoOptionsMessage());
            $this->assertEquals('No options available.', $stateField->getNoOptionsMessage());
            $this->assertEquals('No options available.', $priorityField->getNoOptionsMessage());

        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_page_is_localized_in_ukrainian()
    {
        $originalLocale = app()->getLocale();
        try {
            app()->setLocale('uk');

            $admin = User::factory()->create(['role' => 'admin']);

            $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
                $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
                $mock->shouldReceive('getTicketStates')->andReturn(['new' => 'new']);
                $mock->shouldReceive('getTicketPriorities')->andReturn(['3 normal' => '3 normal']);
            });

            $component = Livewire::actingAs($admin)->test(CreateTicket::class);

            $this->assertEquals('Створити звернення Znuny', $component->instance()->getTitle());

            $components = collect($component->instance()->form->getComponents());

            $sections = [];
            $search = function ($comps) use (&$search, &$sections) {
                foreach ($comps as $c) {
                    if ($c instanceof Section) {
                        $sections[$c->getHeading()] = $c;
                    }
                    if (method_exists($c, 'getChildComponents')) {
                        $search($c->getChildComponents());
                    }
                }
            };
            $search($components);

            $this->assertArrayHasKey('Деталі звернення', $sections);
            $this->assertArrayHasKey('Додаткові параметри звернення', $sections);

            $fields = collect($component->instance()->form->getFlatComponents());

            $queueField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'queue');
            $ownerField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'owner');
            $customerUserField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'customer_user');
            $titleField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'title');
            $bodyField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'body');
            $priorityField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'priority');
            $stateField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'state');
            $lockField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'lock');

            $this->assertEquals('Черга', $queueField->getLabel());
            $this->assertEquals('Власник', $ownerField->getLabel());
            $this->assertEquals('Користувач клієнта', $customerUserField->getLabel());
            $this->assertEquals('Заголовок', $titleField->getLabel());
            $this->assertEquals('Текст звернення', $bodyField->getLabel());
            $this->assertEquals('Пріоритет', $priorityField->getLabel());
            $this->assertEquals('Стан', $stateField->getLabel());
            $this->assertEquals('Блокування', $lockField->getLabel());

            $this->assertEquals('Немає доступних варіантів.', $queueField->getNoOptionsMessage());
            $this->assertEquals('Немає доступних варіантів.', $stateField->getNoOptionsMessage());
            $this->assertEquals('Немає доступних варіантів.', $priorityField->getNoOptionsMessage());

        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_form_option_keys_remain_raw_values()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
            $mock->shouldReceive('getTicketPriorities')->andReturn(['3 normal' => '3 normal']);
            $mock->shouldReceive('getTicketStates')->andReturn(['new' => 'new']);
        });

        $originalLocale = app()->getLocale();
        try {
            app()->setLocale('uk');

            $component = Livewire::actingAs($admin)->test(CreateTicket::class);
            $fields = collect($component->instance()->form->getFlatComponents());

            $priorityField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'priority');
            $stateField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'state');
            $lockField = $fields->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'lock');

            $priorities = $priorityField->getOptions();
            $this->assertArrayHasKey('3 normal', $priorities);
            $this->assertEquals('3 нормальний', $priorities['3 normal']);

            $states = $stateField->getOptions();
            $this->assertArrayHasKey('new', $states);
            $this->assertEquals('Нове', $states['new']);

            $locks = $lockField->getOptions();
            $this->assertArrayHasKey('lock', $locks);
            $this->assertEquals('Заблоковано', $locks['lock']);

        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_mapping_methods_with_raw_and_unknown_values()
    {
        $originalLocale = app()->getLocale();
        try {
            app()->setLocale('uk');

            $this->assertEquals('1 дуже низький', CreateTicket::priorityLabel('1 very low'));
            $this->assertEquals('unknown priority', CreateTicket::priorityLabel('unknown priority'));
            $this->assertNull(CreateTicket::priorityLabel(null));
            $this->assertEquals('', CreateTicket::priorityLabel(''));

            $this->assertEquals('Закрито неуспішно', CreateTicket::stateLabel('closed unsuccessful'));
            $this->assertEquals('unknown state', CreateTicket::stateLabel('unknown state'));
            $this->assertNull(CreateTicket::stateLabel(null));
            $this->assertEquals('', CreateTicket::stateLabel(''));

            $this->assertEquals('Заблоковано', CreateTicket::lockLabel('lock'));
            $this->assertEquals('unknown lock', CreateTicket::lockLabel('unknown lock'));
            $this->assertNull(CreateTicket::lockLabel(null));
            $this->assertEquals('', CreateTicket::lockLabel(''));

        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_error_message_mapping_english()
    {
        $originalLocale = app()->getLocale();
        try {
            app()->setLocale('en');

            $this->assertEquals(
                'Owner, queue, and customer user are required.',
                CreateTicket::errorMessage('Missing required Owner, Queue, or CustomerUser.')
            );
            $this->assertEquals(
                'Ticket title and article body are required.',
                CreateTicket::errorMessage('Ticket title and article body are required.')
            );
            $this->assertEquals(
                'State and priority are required by the Znuny API.',
                CreateTicket::errorMessage('State and Priority are required by Znuny API.')
            );
            $this->assertEquals(
                'Znuny reported success but did not return a ticket ID or ticket number.',
                CreateTicket::errorMessage('Znuny API returned success but missing TicketID/TicketNumber.')
            );

            $this->assertEquals(
                'Could not resolve the customer user: john.doe@example.com',
                CreateTicket::errorMessage('Failed to resolve CustomerUser: john.doe@example.com')
            );
            $this->assertEquals(
                'The customer user “O\'Brian” has no CustomerID/UserCustomerID assigned.',
                CreateTicket::errorMessage("CustomerUser 'O'Brian' has no CustomerID/UserCustomerID assigned.")
            );

            $this->assertEquals(
                'An unexpected error occurred while creating the ticket.',
                CreateTicket::errorMessage('Failed to create ticket: Connection refused')
            );

            $this->assertNull(CreateTicket::errorMessage(null));
            $this->assertEquals('', CreateTicket::errorMessage(''));
            $this->assertEquals('Some unknown preflight error', CreateTicket::errorMessage('Some unknown preflight error'));
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_error_message_mapping_ukrainian()
    {
        $originalLocale = app()->getLocale();
        try {
            app()->setLocale('uk');

            $this->assertEquals(
                'Потрібно вказати власника, чергу та користувача клієнта.',
                CreateTicket::errorMessage('Missing required Owner, Queue, or CustomerUser.')
            );
            $this->assertEquals(
                'Потрібно вказати заголовок і текст звернення.',
                CreateTicket::errorMessage('Ticket title and article body are required.')
            );
            $this->assertEquals(
                'Znuny API вимагає стан і пріоритет.',
                CreateTicket::errorMessage('State and Priority are required by Znuny API.')
            );
            $this->assertEquals(
                'Znuny повідомив про успішне виконання, але не повернув ID або номер звернення.',
                CreateTicket::errorMessage('Znuny API returned success but missing TicketID/TicketNumber.')
            );

            $this->assertEquals(
                'Не вдалося визначити користувача клієнта: john.doe@example.com',
                CreateTicket::errorMessage('Failed to resolve CustomerUser: john.doe@example.com')
            );
            $this->assertEquals(
                'Для користувача клієнта «O\'Brian» не задано CustomerID/UserCustomerID.',
                CreateTicket::errorMessage("CustomerUser 'O'Brian' has no CustomerID/UserCustomerID assigned.")
            );

            $this->assertEquals(
                'Під час створення звернення сталася непередбачена помилка.',
                CreateTicket::errorMessage('Failed to create ticket: Connection refused')
            );

            $this->assertNull(CreateTicket::errorMessage(null));
            $this->assertEquals('', CreateTicket::errorMessage(''));
            $this->assertEquals('Some unknown preflight error', CreateTicket::errorMessage('Some unknown preflight error'));
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_failure_notification_body_uses_localized_messages()
    {
        $originalLocale = app()->getLocale();
        try {
            app()->setLocale('uk');

            $admin = User::factory()->create(['role' => 'admin']);

            $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
                $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
                $mock->shouldReceive('getFilteredQueueOptions')->andReturn(['Raw' => 'Raw']);
                $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->andReturn([1 => 'John Doe']);
                $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->andReturn(['johndoe' => 'John Doe <johndoe>']);
                $mock->shouldReceive('resolveTemplateCandidate')->andReturn('johndoe');
                $mock->shouldReceive('getTicketStates')->andReturn(['open' => 'open']);
                $mock->shouldReceive('getTicketPriorities')->andReturn(['3 normal' => '3 normal']);
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
                        'errors' => [
                            'Missing required Owner, Queue, or CustomerUser.',
                            'Some unknown preflight error',
                            'Failed to create ticket: Connection refused',
                        ],
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
                ->assertNotified(
                    Notification::make()
                        ->title('Помилка створення звернення')
                        ->body('Потрібно вказати власника, чергу та користувача клієнта.<br>Some unknown preflight error<br>Під час створення звернення сталася непередбачена помилка.')
                        ->danger()
                        ->persistent()
                );

        } finally {
            app()->setLocale($originalLocale);
        }
    }
}
