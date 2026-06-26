<x-filament-panels::page class="zbx-current-problems-page" max-width="full">
    <style>
        [x-cloak] { display: none !important; }

        /* Page Width overrides */
        .zbx-current-problems-page { width: 100%; }
        .fi-main:has(.zbx-current-problems-page) .fi-page-content { max-width: none !important; }

        .zbx-page-stack,
        .zbx-table-container,
        .zbx-toolbar {
            width: 100%;
            max-width: none;
        }

        .zbx-page-stack {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        /* Toolbar Section */
        .zbx-toolbar {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        @media (min-width: 768px) {
            .zbx-toolbar {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        .zbx-toolbar-filters {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            flex: 1;
        }

        .zbx-toolbar-search {
            flex: 1;
            max-width: 300px;
        }

        .zbx-toolbar-select {
            flex: 1;
            max-width: 250px;
        }

        .zbx-dropdown-menu {
            position: absolute;
            top: 100%;
            left: 0;
            z-index: 50;
            margin-top: 4px;
            background-color: var(--zbx-table-bg);
            border: 1px solid var(--zbx-table-border);
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            min-width: 200px;
            padding: 0.5rem;
            max-height: 300px;
            overflow-y: auto;
        }

        .zbx-dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.375rem 0.5rem;
            cursor: pointer;
            border-radius: 0.25rem;
            color: var(--zbx-table-text);
        }

        .zbx-dropdown-item:hover {
            background-color: var(--zbx-table-hover);
        }

        .zbx-toolbar-count {
            font-size: 0.875rem;
            color: #4b5563;
            font-weight: 500;
        }
        :is(.dark) .zbx-toolbar-count { color: #d1d5db; }

        /* Table Section */
        .zbx-table-container {
            --zbx-table-bg: var(--color-white);
            --zbx-table-head-bg: var(--gray-50);
            --zbx-table-border: var(--gray-200);
            --zbx-table-text: var(--gray-950);
            --zbx-table-muted: var(--gray-500);
            --zbx-table-hover: #eaf3ff;
            background-color: var(--zbx-table-bg);
            border: 1px solid var(--zbx-table-border);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            overflow-x: auto;
        }
        :is(.dark) .zbx-table-container {
            --zbx-table-bg: var(--gray-900);
            --zbx-table-head-bg: #ffffff0d;
            --zbx-table-border: #ffffff0d;
            --zbx-table-text: var(--color-white);
            --zbx-table-muted: var(--gray-400);
            --zbx-table-hover: #ffffff0d;
        }

        .zbx-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.8125rem;
        }

        .zbx-table th {
            background-color: var(--zbx-table-head-bg);
            color: var(--zbx-table-text);
            font-weight: 500;
            padding: 5px 10px;
            border-bottom: 1px solid var(--zbx-table-border);
            white-space: nowrap;
        }

        .zbx-th-button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: none;
            border: none;
            padding: 0;
            color: inherit;
            font: inherit;
            cursor: pointer;
            width: 100%;
            text-align: left;
        }
        .zbx-th-button:hover { color: var(--zbx-table-text); }

        .zbx-sort-icon {
            width: 14px;
            height: 14px;
            color: #9ca3af;
        }
        .zbx-sort-icon.active { color: #3b82f6; }

        .zbx-table tbody { border-bottom: 1px solid var(--zbx-table-border); }

        .zbx-table tbody tr.zbx-problem-row:not(:last-child) > td {
            border-bottom: 1px solid var(--zbx-table-border);
        }

        .zbx-problem-row td {
            padding: 6px 10px;
            color: var(--zbx-table-text);
        }
        .zbx-problem-row > td { transition: background-color 0.12s ease-in-out; }
        .zbx-table tbody tr.zbx-problem-row:hover > td { background-color: var(--zbx-table-hover) !important; }

        .zbx-ticket-col {
            font-weight: 600;
            color: var(--zbx-table-text);
        }

        .zbx-muted-text {
            color: var(--zbx-table-muted);
        }

        /* Empty states */
        .zbx-empty-state {
            padding: 48px 24px;
            text-align: center;
        }
        .zbx-empty-icon {
            width: 48px;
            height: 48px;
            margin: 0 auto 16px auto;
            color: #9ca3af;
        }
        .zbx-empty-state h3 {
            font-size: 1.125rem;
            font-weight: 600;
            color: #111827;
            margin-bottom: 4px;
        }
        :is(.dark) .zbx-empty-state h3 { color: #f9fafb; }
        .zbx-empty-state p {
            color: #6b7280;
            font-size: 0.875rem;
        }
        :is(.dark) .zbx-empty-state p { color: #9ca3af; }

        .zbx-link-icon-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .zbx-link-icon {
            width: 1.1rem;
            height: 1.1rem;
        }

        .zbx-icon-active { color: #0284c7; }
        .zbx-icon-resolved { color: #64748b; opacity: 0.7; }
        .zbx-icon-warning { color: #ea580c; }

        :is(.dark) .zbx-icon-active { color: #38bdf8; }
        :is(.dark) .zbx-icon-resolved { color: #94a3b8; }
        :is(.dark) .zbx-icon-warning { color: #fb923c; }

        .zbx-legend {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem;
            color: var(--zbx-table-muted);
            font-size: 0.75rem;
        }

        .zbx-legend-item {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }

        .zbx-legend-icon {
            width: 1rem;
            height: 1rem;
            flex-shrink: 0;
        }

    </style>

    <div class="zbx-page-stack" wire:poll.{{ $this->getRefreshIntervalString() }}>
        @php
            $data = $this->ticketData();
            $tickets = $data['rows'];
            $filterOptions = $data['filter_options'];
            $total = $data['total'];
            $page = $data['page'];
            $perPage = $data['per_page'];
            $lastPage = $data['last_page'];
            $offset = ($page - 1) * $perPage;
            $startCount = $total > 0 ? $offset + 1 : 0;
            $endCount = min($offset + $perPage, $total);
        @endphp

        <div class="zbx-legend" aria-label="Ticket Workspace legend">
            <span class="zbx-legend-item">
                <x-filament::icon icon="heroicon-s-link" class="zbx-legend-icon zbx-icon-active" />
                <span>Linked to active Zabbix problem</span>
            </span>

            <span class="zbx-legend-item">
                <x-filament::icon icon="heroicon-s-link-slash" class="zbx-legend-icon zbx-icon-resolved" />
                <span>Linked to resolved Zabbix problem</span>
            </span>

            <span class="zbx-legend-item">
                <x-filament::icon icon="heroicon-s-exclamation-triangle" class="zbx-legend-icon zbx-icon-warning" />
                <span>Active problem on closed/merged ticket</span>
            </span>
        </div>

        <div class="zbx-toolbar">
            <div class="zbx-toolbar-filters">
                <div class="zbx-toolbar-search">
                    <x-filament::input.wrapper icon="heroicon-m-magnifying-glass">
                        <x-filament::input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Search ticket # or title..."
                        />
                    </x-filament::input.wrapper>
                </div>

                <div class="zbx-toolbar-select">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="linkFilter">
                            @foreach($filterOptions['link_status'] as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                <div class="zbx-toolbar-select" x-data="{ open: false }" @click.outside="open = false" style="position: relative;">
                    @php
                        $selectedCount = count($this->stateTypeFilter);
                        if ($selectedCount === 0) {
                            $label = 'State Types';
                        } elseif ($selectedCount === 1) {
                            $label = $filterOptions['state_types'][reset($this->stateTypeFilter)] ?? reset($this->stateTypeFilter);
                        } else {
                            $label = $selectedCount . ' State Types';
                        }
                    @endphp
                    <x-filament::input.wrapper>
                        <button type="button" @click.stop="open = !open" style="width: 100%; display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 0.75rem; min-height: 36px; background: transparent; border: none; outline: none; cursor: pointer;">
                            <span style="font-size: 0.875rem; color: var(--zbx-table-text);">{{ $label }}</span>
                            <x-filament::icon icon="heroicon-m-chevron-down" style="width: 1rem; height: 1rem; color: var(--zbx-table-muted);" />
                        </button>
                    </x-filament::input.wrapper>

                    <div x-show="open" x-transition x-cloak @click.stop class="zbx-dropdown-menu">
                        @foreach($filterOptions['state_types'] as $val => $typeLabel)
                            <label class="zbx-dropdown-item">
                                <input type="checkbox" wire:model.live="stateTypeFilter" value="{{ $val }}" style="border-radius: 0.25rem; border-color: var(--zbx-table-border); background-color: var(--zbx-table-bg); color: var(--primary-600);">
                                <span style="font-size: 0.875rem;">{{ $typeLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="zbx-toolbar-select">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="queueFilter">
                            <option value="">Any Queue</option>
                            @foreach($filterOptions['queues'] as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                <div class="zbx-toolbar-select">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="ownerFilter">
                            <option value="">Any Owner</option>
                            @foreach($filterOptions['owners'] as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            </div>

            <div class="zbx-toolbar-count" style="display: flex; flex-direction: column; align-items: flex-end; gap: 8px;">
                <span>Showing {{ $startCount }}-{{ $endCount }} of {{ $total }} tickets</span>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span>Per page:</span>
                    <x-filament::input.wrapper style="width: auto;">
                        <x-filament::input.select wire:model.live="perPage">
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="250">250</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            </div>
        </div>

        <div class="zbx-table-container">
            @if(empty($tickets))
                <div class="zbx-empty-state">
                    @if(empty($this->search) && $this->linkFilter === 'all' && empty($this->stateTypeFilter) && empty($this->queueFilter) && empty($this->ownerFilter))
                        <x-filament::icon icon="heroicon-o-inbox" class="zbx-empty-icon" />
                        <h3>Ticket cache is empty</h3>
                        <p>Run the Ticket Workspace cache warmer.</p>
                    @else
                        <x-filament::icon icon="heroicon-o-magnifying-glass" class="zbx-empty-icon" />
                        <h3>No matching tickets</h3>
                        <p>Try adjusting your search query or filters.</p>
                    @endif
                </div>
            @else
                <table class="zbx-table">
                    <thead>
                        <tr>
                            <th style="width: 42px;"></th>
                            <th style="width: 140px;">
                                <button type="button" class="zbx-th-button" wire:click="sortBy('TicketNumber')">
                                    Ticket#
                                    @if($this->sortField === 'TicketNumber')
                                        <x-filament::icon icon="{{ $this->sortDirection === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="zbx-sort-icon active" />
                                    @endif
                                </button>
                            </th>
                            <th>
                                <button type="button" class="zbx-th-button" wire:click="sortBy('Title')">
                                    Title
                                    @if($this->sortField === 'Title')
                                        <x-filament::icon icon="{{ $this->sortDirection === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="zbx-sort-icon active" />
                                    @endif
                                </button>
                            </th>
                            <th>
                                <button type="button" class="zbx-th-button" wire:click="sortBy('Queue')">
                                    Queue
                                    @if($this->sortField === 'Queue')
                                        <x-filament::icon icon="{{ $this->sortDirection === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="zbx-sort-icon active" />
                                    @endif
                                </button>
                            </th>
                            <th>
                                <button type="button" class="zbx-th-button" wire:click="sortBy('Owner')">
                                    Owner
                                    @if($this->sortField === 'Owner')
                                        <x-filament::icon icon="{{ $this->sortDirection === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="zbx-sort-icon active" />
                                    @endif
                                </button>
                            </th>
                            <th>
                                <button type="button" class="zbx-th-button" wire:click="sortBy('State')">
                                    State / Type
                                    @if($this->sortField === 'State')
                                        <x-filament::icon icon="{{ $this->sortDirection === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="zbx-sort-icon active" />
                                    @endif
                                </button>
                            </th>
                            <th>
                                <button type="button" class="zbx-th-button" wire:click="sortBy('Priority')">
                                    Priority
                                    @if($this->sortField === 'Priority')
                                        <x-filament::icon icon="{{ $this->sortDirection === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="zbx-sort-icon active" />
                                    @endif
                                </button>
                            </th>
                            <th style="text-align: right;">
                                Articles
                            </th>
                            <th style="text-align: right;">
                                <button type="button" class="zbx-th-button" style="justify-content: flex-end;" wire:click="sortBy('Changed')">
                                    Changed
                                    @if($this->sortField === 'Changed')
                                        <x-filament::icon icon="{{ $this->sortDirection === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="zbx-sort-icon active" />
                                    @endif
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($tickets as $ticket)
                            <tr class="zbx-problem-row" style="cursor: pointer;" wire:click="openTicketDetails({{ $ticket['TicketID'] }})">
                                <td style="text-align: center;">
                                    @if($ticket['is_linked_to_zabbix_problem'])
                                        <div class="zbx-link-icon-wrap"
                                             title="Host: {{ $ticket['linked_problem_host'] ?? 'Unknown' }}&#10;Problem: {{ $ticket['linked_problem_summary'] ?? 'Unknown' }}&#10;State: {{ $ticket['linked_problem_is_active'] ? 'Active' : 'Resolved' }}&#10;Age: {{ $ticket['linked_problem_age_label'] ?? 'N/A' }}">
                                            @if($ticket['linked_problem_has_warning'])
                                                <x-filament::icon icon="heroicon-s-exclamation-triangle" class="zbx-link-icon zbx-icon-warning" />
                                            @elseif($ticket['linked_problem_is_active'])
                                                <x-filament::icon icon="heroicon-s-link" class="zbx-link-icon zbx-icon-active" />
                                            @else
                                                <x-filament::icon icon="heroicon-o-link" class="zbx-link-icon zbx-icon-resolved" />
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="zbx-ticket-col">
                                    {{ $ticket['TicketNumber'] }}
                                </td>
                                <td>
                                    {{ $ticket['Title'] }}
                                </td>
                                <td>
                                    {{ $ticket['Queue'] }}
                                </td>
                                <td>
                                    {{ $ticket['Owner'] }}
                                    @if(!empty($ticket['CustomerUserID']))
                                        <br><span class="zbx-muted-text" style="font-size: 0.7rem;">Cust: {{ $ticket['CustomerUserID'] }}</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $ticket['State'] }}
                                    @if(!empty($ticket['StateType']))
                                        <span class="zbx-muted-text" style="font-size: 0.7rem;">({{ $ticket['StateType'] }})</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $ticket['Priority'] }}
                                </td>
                                <td style="text-align: right;">
                                    {{ $ticket['ArticleCount'] }}
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    @if(!empty($ticket['Changed']))
                                        @php
                                            $changedAge = \Carbon\Carbon::parse($ticket['Changed'])->diffInSeconds(now());
                                            $changedStr = app(\App\Services\Zabbix\ZabbixProblemFormatter::class)->formatAge($changedAge);
                                        @endphp
                                        {{ $changedStr }} ago
                                    @else
                                        N/A
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if($total > 0)
                <div style="padding: 16px; border-top: 1px solid var(--border-color, #e5e7eb); display: flex; justify-content: space-between; align-items: center; font-size: 0.875rem;">
                    <div style="color: #6b7280;">
                        Showing {{ $startCount }} to {{ $endCount }} of {{ $total }} results
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <x-filament::button
                            color="gray"
                            wire:click="setPage({{ max($page - 1, 1) }})"
                            :disabled="$page <= 1"
                        >
                            Previous
                        </x-filament::button>

                        <x-filament::button
                            color="gray"
                            wire:click="setPage({{ min($page + 1, $lastPage) }})"
                            :disabled="$page >= $lastPage"
                        >
                            Next
                        </x-filament::button>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <x-filament::modal id="ticket-details-modal" width="2xl">
        <x-slot name="heading">
            Ticket Details
        </x-slot>

        <div>
            @if($selectedTicket)
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <div>
                        <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Ticket Number</strong>
                        <div style="font-weight: 500;">{{ $selectedTicket['TicketNumber'] ?? '-' }}</div>
                    </div>
                    <div>
                        <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Title</strong>
                        <div>{{ $selectedTicket['Title'] ?? '-' }}</div>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div>
                            <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Queue</strong>
                            <div>{{ $selectedTicket['Queue'] ?? '-' }}</div>
                        </div>
                        <div>
                            <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Owner</strong>
                            <div>{{ $selectedTicket['Owner'] ?? '-' }}</div>
                        </div>
                        <div>
                            <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Customer User</strong>
                            <div>{{ $selectedTicket['CustomerUserID'] ?: '-' }}</div>
                        </div>
                        <div>
                            <strong style="display: block; font-size: 0.875rem; color: #6b7280;">State / Type</strong>
                            <div>
                                <span>{{ $selectedTicket['State'] ?? '-' }}</span>
                                @if(!empty($selectedTicket['StateType']))
                                    <span style="color: #6b7280; font-size: 0.875rem;">({{ $selectedTicket['StateType'] }})</span>
                                @endif
                            </div>
                        </div>
                        <div>
                            <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Priority</strong>
                            <div>{{ $selectedTicket['Priority'] ?? '-' }}</div>
                        </div>
                        <div>
                            <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Type</strong>
                            <div>{{ $selectedTicket['Type'] ?: '-' }}</div>
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; border-top: 1px solid var(--border-color, #e5e7eb); padding-top: 16px;">
                        <div>
                            <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Created</strong>
                            <div>{{ $selectedTicket['Created'] ?? '-' }}</div>
                        </div>
                        <div>
                            <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Changed</strong>
                            <div>{{ $selectedTicket['Changed'] ?? '-' }}</div>
                        </div>
                        <div>
                            <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Article Count</strong>
                            <div>{{ $selectedTicket['ArticleCount'] ?? '-' }}</div>
                        </div>
                        <div>
                            <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Last Article</strong>
                            <div>{{ $selectedTicket['LastArticleCreated'] ?: '-' }}</div>
                        </div>
                    </div>

                    @if(!empty($selectedTicket['is_linked_to_zabbix_problem']))
                        <div style="margin-top: 16px; padding: 12px; background: rgba(59, 130, 246, 0.05); border: 1px solid rgba(59, 130, 246, 0.2); border-radius: 8px;">
                            <strong style="display: block; font-size: 0.875rem; color: #0369a1; margin-bottom: 8px;">Linked Zabbix Problem</strong>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 0.875rem;">
                                <div><span style="color: #6b7280;">Host:</span> <span>{{ $selectedTicket['linked_problem_host'] ?? 'Unknown' }}</span></div>
                                <div>
                                    <span style="color: #6b7280;">State:</span>
                                    <span style="{{ !empty($selectedTicket['linked_problem_is_active']) ? 'color: #ea580c; font-weight: 500;' : 'color: #059669;' }}">
                                        {{ !empty($selectedTicket['linked_problem_is_active']) ? 'Active' : 'Resolved' }}
                                    </span>
                                </div>
                                <div style="grid-column: span 2;"><span style="color: #6b7280;">Problem:</span> <span>{{ $selectedTicket['linked_problem_summary'] ?? 'Unknown' }}</span></div>
                                <div><span style="color: #6b7280;">Event ID:</span> <span>{{ $selectedTicket['linked_problem_event_id'] ?? '-' }}</span></div>
                                <div><span style="color: #6b7280;">Age:</span> <span>{{ $selectedTicket['linked_problem_age_label'] ?? 'N/A' }}</span></div>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </x-filament::modal>
</x-filament-panels::page>
