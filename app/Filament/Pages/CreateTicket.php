<?php

namespace App\Filament\Pages;

use App\Filament\Schemas\ZnunyTicketCreationSchema;
use App\Services\Znuny\ZnunyStandaloneTicketCreationService;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

class CreateTicket extends Page implements HasForms
{
    use InteractsWithForms;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.znuny');
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?int $navigationSort = 9;

    public static function getNavigationLabel(): string
    {
        return __('create_ticket.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('create_ticket.title');
    }

    public static function priorityLabel(?string $priority): ?string
    {
        if ($priority === null || $priority === '') {
            return $priority;
        }

        $key = 'create_ticket.priorities.'.$priority;
        $translated = __($key);

        return $translated === $key ? $priority : $translated;
    }

    public static function stateLabel(?string $state): ?string
    {
        if ($state === null || $state === '') {
            return $state;
        }

        $key = 'create_ticket.states.'.$state;
        $translated = __($key);

        return $translated === $key ? $state : $translated;
    }

    public static function lockLabel(?string $lock): ?string
    {
        if ($lock === null || $lock === '') {
            return $lock;
        }

        $key = 'create_ticket.locks.'.$lock;
        $translated = __($key);

        return $translated === $key ? $lock : $translated;
    }

    public static function errorMessage(?string $error): ?string
    {
        if ($error === null || $error === '') {
            return $error;
        }

        if ($error === 'Missing required Owner, Queue, or CustomerUser.') {
            return __('create_ticket.errors.missing_owner_queue_user');
        }

        if ($error === 'Ticket title and article body are required.') {
            return __('create_ticket.errors.missing_title_body');
        }

        if ($error === 'State and Priority are required by Znuny API.') {
            return __('create_ticket.errors.missing_state_priority');
        }

        if ($error === 'Znuny API returned success but missing TicketID/TicketNumber.') {
            return __('create_ticket.errors.missing_ticket_number');
        }

        if (str_starts_with($error, 'Failed to resolve CustomerUser: ')) {
            $customerUser = substr($error, strlen('Failed to resolve CustomerUser: '));

            return __('create_ticket.errors.failed_to_resolve_user', ['customer_user' => $customerUser]);
        }

        if (str_starts_with($error, "CustomerUser '") && str_ends_with($error, "' has no CustomerID/UserCustomerID assigned.")) {
            $customerUser = substr($error, strlen("CustomerUser '"), -strlen("' has no CustomerID/UserCustomerID assigned."));

            return __('create_ticket.errors.user_has_no_customer_id', ['customer_user' => $customerUser]);
        }

        if (str_starts_with($error, 'Failed to create ticket: ')) {
            return __('create_ticket.errors.unexpected_error');
        }

        return $error;
    }

    protected string $view = 'filament.pages.create-ticket';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema(ZnunyTicketCreationSchema::schema())
            ->statePath('data');
    }

    public function create(ZnunyStandaloneTicketCreationService $creationService): void
    {
        $data = $this->form->getState();

        $result = $creationService->createTicket(
            ownerId: $data['owner'] ?? '',
            queue: $data['queue'] ?? '',
            customerUser: $data['customer_user'] ?? '',
            title: $data['title'] ?? '',
            articleBody: $data['body'] ?? '',
            state: $data['state'] ?? null,
            priority: $data['priority'] ?? null,
            lock: $data['lock'] ?? null
        );

        if (! $result['success']) {
            $localizedErrors = array_map(fn ($error) => self::errorMessage($error), $result['errors']);

            Notification::make()
                ->title(__('create_ticket.notifications.creation_failed.title'))
                ->body(implode('<br>', $localizedErrors))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('create_ticket.notifications.created.title'))
            ->body(__('create_ticket.notifications.created.body', ['ticket_number' => $result['ticket_number']]))
            ->success()
            ->send();

        $this->form->fill();
    }
}
