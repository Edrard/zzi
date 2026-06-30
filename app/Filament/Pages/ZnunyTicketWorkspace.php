<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ZabbixTickets\Actions\ZabbixTicketDetailsAction;
use App\Services\SettingsService;
use App\Services\Znuny\ClosedTicketCacheService;
use App\Services\Znuny\ClosedTicketSyncService;
use App\Services\Znuny\ZnunyTicketCacheService;
use App\Services\Znuny\ZnunyTicketWorkspaceCacheReader;
use App\Services\Znuny\ZnunyTicketWorkspaceStateTypeMapper;
use App\Support\Pagination\PaginationSettings;
use App\Support\Polling\UiPollInterval;
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

    public int $perPage;

    public string $sortField = 'Changed';

    public string $sortDirection = 'desc';

    public function viewTicketAction(): Action
    {
        return ZabbixTicketDetailsAction::make('viewTicket')
            ->record(function (array $arguments) {
                $ticketId = $arguments['znuny_ticket_id'] ?? null;
                if (! $ticketId) {
                    return null;
                }

                $data = $this->ticketData();
                foreach ($data['rows'] as $row) {
                    if (($row['TicketID'] ?? 0) === (int) $ticketId) {
                        $row['__key'] = $row['TicketID'];

                        return $row;
                    }
                }

                // 3. Fallback direct cache lookup for tickets that might have just moved
                $activeCache = app(ZnunyTicketCacheService::class);
                $closedCache = app(ClosedTicketCacheService::class);
                $reader = app(ZnunyTicketWorkspaceCacheReader::class);

                $rawTicket = $activeCache->getTicket($ticketId);

                if (! $rawTicket) {
                    $rawTicket = $closedCache->getTicket($ticketId);
                }

                if ($rawTicket) {
                    $row = $reader->normalizeSingleTicket($rawTicket);
                    $row['__key'] = $row['TicketID'];

                    return $row;
                }

                return null;
            });
    }

    public function mount()
    {
        $this->perPage = app(PaginationSettings::class)->defaultPerPage();
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

    public function updatedPerPage($value)
    {
        $this->perPage = app(PaginationSettings::class)->normalizePerPage($value);
    }

    public function updated($property)
    {
        if (in_array($property, ['search', 'linkFilter', 'stateTypeFilter', 'queueFilter', 'ownerFilter', 'perPage'])) {
            $this->page = 1;
        }
    }

    public function applyStatePreset(string $preset): void
    {
        $presets = [
            'open' => ['new', 'open', 'pending reminder', 'pending auto'],
            'closed' => ['closed'],
            'merged' => ['merged'],
            'all' => ['new', 'open', 'pending reminder', 'pending auto', 'closed', 'merged'],
        ];

        if (! array_key_exists($preset, $presets)) {
            return;
        }

        $this->stateTypeFilter = $presets[$preset];
        $this->page = 1;
    }

    public function activeStatePreset(): ?string
    {
        $current = array_values(array_filter(array_map('trim', array_map('strtolower', $this->stateTypeFilter))));
        sort($current);

        $presets = [
            'open' => ['new', 'open', 'pending reminder', 'pending auto'],
            'closed' => ['closed'],
            'merged' => ['merged'],
            'all' => ['closed', 'merged', 'new', 'open', 'pending auto', 'pending reminder'],
        ];

        foreach ($presets as $name => $types) {
            $sortedTypes = $types;
            sort($sortedTypes);
            if ($current === $sortedTypes) {
                return $name;
            }
        }

        return null;
    }

    public static function canAccess(): bool
    {
        return in_array(auth()->user()->role ?? '', ['admin', 'operator', 'viewer'], true);
    }

    public function ticketData(): array
    {
        $reader = app(ZnunyTicketWorkspaceCacheReader::class);

        $data = $reader->getTicketsPaginated(
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

        return $data;
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

    protected function getActions(): array
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
            $exitCode = Artisan::call('znuny:warm-ticket-workspace-cache', ['--manual' => true]);
            $output = trim(Artisan::output());

            if ($exitCode !== 0) {
                Notification::make()
                    ->title('Failed to refresh Ticket Workspace')
                    ->body($output !== '' ? $output : 'The cache warmer command failed.')
                    ->danger()
                    ->send();

                return;
            }

            $this->page = 1;

            $closedSyncMessage = '';
            $isWarning = false;

            try {
                $service = app(ClosedTicketSyncService::class);
                $result = $service->syncManual();

                if (! empty($result['error_message'])) {
                    $closedSyncMessage = "\nRecent closed sync failed: {$result['error_message']}";
                    $isWarning = true;
                } elseif (($result['effective_mode'] ?? '') === 'skipped') {
                    $closedSyncMessage = "\nRecent closed sync skipped (locked).";
                    $isWarning = true;
                } else {
                    $closedSyncMessage = "\nRecent closed sync completed (Fetched {$result['fetched_count']}, Cached {$result['cached_count']}).";
                }
            } catch (\Throwable $e) {
                $closedSyncMessage = "\nRecent closed sync failed: ".$e->getMessage();
                $isWarning = true;
            }

            $message = 'Active refresh completed.';
            if ($output !== '') {
                $message .= "\n".$output;
            }
            $message .= $closedSyncMessage;

            $notification = Notification::make()
                ->title('Ticket Workspace refreshed successfully')
                ->body($message);

            if ($isWarning) {
                $notification->warning();
            } else {
                $notification->success();
            }

            $notification->send();
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
        return UiPollInterval::getLivewireString();
    }
}
