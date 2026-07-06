<?php

namespace App\Filament\Pages;

use App\Filament\Schemas\ZnunyTicketCreationSchema;
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

    public function create(): void
    {
        // Placeholder for future ticket creation logic
        Notification::make()
            ->title('Not Implemented')
            ->body('Ticket creation backend is not yet implemented.')
            ->warning()
            ->send();
    }
}
