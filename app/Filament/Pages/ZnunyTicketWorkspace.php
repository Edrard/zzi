<?php

namespace App\Filament\Pages;

use App\Services\Znuny\ZnunyTicketWorkspaceCacheReader;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;

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

    public ?string $stateTypeFilter = null;

    public string $sortField = 'Changed';

    public string $sortDirection = 'desc';

    public static function canAccess(): bool
    {
        return in_array(auth()->user()->role ?? '', ['admin', 'operator', 'viewer'], true);
    }

    public function tickets(): array
    {
        $reader = app(ZnunyTicketWorkspaceCacheReader::class);
        $tickets = $reader->getTickets([
            'search' => $this->search,
            'link_status' => $this->linkFilter,
            'state_type' => $this->stateTypeFilter,
        ]);

        usort($tickets, function ($a, $b) {
            $valA = $a[$this->sortField] ?? null;
            $valB = $b[$this->sortField] ?? null;

            if ($valA === $valB) {
                return 0;
            }

            $cmp = $valA <=> $valB;

            return $this->sortDirection === 'asc' ? $cmp : -$cmp;
        });

        return $tickets;
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
    }

    public function filterOptions(): array
    {
        return [
            'link_status' => [
                'all' => 'All tickets',
                'linked' => 'Linked to Zabbix problem',
                'linked_active' => 'Linked to active problem',
                'linked_resolved' => 'Linked to resolved/recovered problem',
                'unlinked' => 'Unlinked tickets',
            ],
            // For now, state_type uses a few static known options or we could extract them dynamically.
            // Using a simple set of common ones for Phase 1.
            'state_types' => [
                '' => 'Any State Type',
                'new' => 'New',
                'open' => 'Open',
                'pending reminder' => 'Pending Reminder',
                'pending auto' => 'Pending Auto',
                'closed' => 'Closed',
                'merged' => 'Merged',
            ],
        ];
    }
}
