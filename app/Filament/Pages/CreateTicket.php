<?php

namespace App\Filament\Pages;

use App\Filament\Schemas\ZnunyTicketCreationSchema;
use App\Services\Znuny\ZnunyStandaloneTicketCreationService;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

class CreateTicket extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\UnitEnum|null $navigationGroup = 'Znuny';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Create Ticket';

    protected static ?string $title = 'Create Znuny Ticket';

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
