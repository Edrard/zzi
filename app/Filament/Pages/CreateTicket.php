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

    protected static ?int $navigationSort = 40;

    public static function getNavigationLabel(): string
    {
        return __('navigation.pages.create_ticket.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('navigation.pages.create_ticket.title');
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
            Notification::make()
                ->title('Ticket Creation Failed')
                ->body(implode('<br>', $result['errors']))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title('Ticket Created')
            ->body("Znuny ticket {$result['ticket_number']} has been created successfully.")
            ->success()
            ->send();

        $this->form->fill();
    }
}
