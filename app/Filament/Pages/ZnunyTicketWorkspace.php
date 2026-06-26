<?php

namespace App\Filament\Pages;

use App\Services\SettingsService;
use App\Services\Znuny\ZnunyTicketWorkspaceCacheReader;
use App\Services\Znuny\ZnunyTicketWorkspaceStateTypeMapper;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Artisan;

class ZnunyTicketWorkspace extends Page
{
    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Znuny';

    protected static ?string $navigationLabel = 'Ticket Workspace';

    protected static ?string $title = 'Ticket Workspace';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.znuny-ticket-workspace';

    public string $search = '';

    public string $linkFilter = 'all';

    public array $stateTypeFilter = [];

    public ?string $queueFilter = null;

    public ?string $ownerFilter = null;

    public int $page = 1;

    public int $perPage = 50;

    public string $sortField = 'Changed';

    public string $sortDirection = 'desc';

    public ?int $selectedTicketId = null;

    public ?array $selectedTicket = null;

    public function openTicketDetails(int $ticketId): void
    {
        $this->selectedTicketId = $ticketId;
        $data = $this->ticketData();
        foreach ($data['rows'] as $row) {
            if (($row['TicketID'] ?? 0) === $ticketId) {
                $this->selectedTicket = $row;
                break;
            }
        }

        $this->dispatch('open-modal', id: 'ticket-details-modal');
    }

    public function mount()
    {
        $activeIdsJson = SettingsService::string('znuny_ticket_workspace_active_state_type_ids', '[]');
        $activeIds = json_decode($activeIdsJson, true) ?? [];
        if (! empty($activeIds) && is_array($activeIds)) {
            $mapper = app(ZnunyTicketWorkspaceStateTypeMapper::class);
            $mapped = $mapper->mapInternalIdsToZnunyTypes($activeIds);
            if (! empty($mapped)) {
                $this->stateTypeFilter = array_map('strtolower', $mapped);
            }
        } else {
            $this->stateTypeFilter = ['new', 'open', 'pending reminder', 'pending auto'];
        }
    }

    public function updated($property)
    {
        if (in_array($property, ['search', 'linkFilter', 'stateTypeFilter', 'queueFilter', 'ownerFilter', 'perPage'])) {
            $this->page = 1;
        }
    }

    public static function canAccess(): bool
    {
        return in_array(auth()->user()->role ?? '', ['admin', 'operator', 'viewer'], true);
    }

    public function ticketData(): array
    {
        $reader = app(ZnunyTicketWorkspaceCacheReader::class);

        return $reader->getTicketsPaginated(
            [
                'search' => $this->search,
                'link_status' => $this->linkFilter,
                'state_types' => $this->stateTypeFilter,
                'queue' => $this->queueFilter,
                'owner' => $this->ownerFilter,
            ],
            $this->page,
            $this->perPage,
            $this->sortField,
            $this->sortDirection
        );
    }

    public function setPage(int $page): void
    {
        $this->page = $page;
    }

    public function sortBy(string $field): void
    {
        $allowed = ['TicketNumber', 'Title', 'Queue', 'Owner', 'Priority', 'Changed', 'State'];
        if (! in_array($field, $allowed)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'desc';
        }
        $this->page = 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh from Znuny')
                ->icon('heroicon-o-arrow-path')
                ->action('refreshFromZnuny')
                ->visible(fn () => in_array(auth()->user()->role ?? '', ['admin', 'operator'], true)),
        ];
    }

    public function refreshFromZnuny(): void
    {
        abort_unless(in_array(auth()->user()->role ?? '', ['admin', 'operator'], true), 403);

        try {
            $exitCode = Artisan::call('znuny:warm-ticket-workspace-cache');
            $output = trim(Artisan::output());

            if ($exitCode === 0) {
                $this->page = 1;

                $message = "Ticket Workspace cache refresh completed.";
                if ($output !== '') {
                    $message .= "\n".$output;
                }

                Notification::make()
                    ->title('Ticket Workspace refreshed successfully')
                    ->body($message)
                    ->success()
                    ->send();

                return;
            }

            Notification::make()
                ->title('Failed to refresh Ticket Workspace')
                ->body($output !== '' ? $output : 'The cache warmer command failed.')
                ->danger()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('An error occurred while refreshing Ticket Workspace')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getRefreshIntervalString(): string
    {
        $minutes = SettingsService::int('znuny_ticket_cache_refresh_interval_minutes', 5) ?? 5;

        $seconds = max(60, $minutes * 60);

        return "{$seconds}s";
    }
}
