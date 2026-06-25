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
            gap: 24px;
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

        .zbx-toolbar-count {
            font-size: 0.875rem;
            color: #4b5563;
            font-weight: 500;
        }
        :is(.dark) .zbx-toolbar-count { color: #d1d5db; }

        /* Table Section */
        .zbx-table-container {
            background-color: var(--bg-color, #ffffff);
            border: 1px solid var(--border-color, #e5e7eb);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            overflow-x: auto;
        }
        :is(.dark) .zbx-table-container {
            --bg-color: #111827;
            --border-color: rgba(255, 255, 255, 0.1);
        }

        .zbx-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.8125rem;
        }

        .zbx-table th {
            background-color: #f9fafb;
            color: #374151;
            font-weight: 500;
            padding: 5px 10px;
            border-bottom: 1px solid var(--border-color, #e5e7eb);
            white-space: nowrap;
        }
        :is(.dark) .zbx-table th {
            background-color: #1f2937;
            color: #f9fafb;
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
        .zbx-th-button:hover { color: #111827; }
        :is(.dark) .zbx-th-button:hover { color: #ffffff; }

        .zbx-sort-icon {
            width: 14px;
            height: 14px;
            color: #9ca3af;
        }
        .zbx-sort-icon.active { color: #3b82f6; }

        .zbx-table tbody { border-bottom: 1px solid var(--border-color, #e5e7eb); }

        .zbx-problem-row td {
            padding: 6px 10px;
            color: #4b5563;
        }
        .zbx-problem-row > td { transition: background-color 0.12s ease-in-out; }
        .zbx-table tbody tr.zbx-problem-row:hover > td { background-color: #eaf3ff !important; }
        :is(.dark) .zbx-table tbody tr.zbx-problem-row:hover > td { background-color: rgba(59, 130, 246, 0.14) !important; }
        :is(.dark) .zbx-problem-row td { color: #d1d5db; }
        
        .zbx-ticket-col {
            font-weight: 600;
            color: #111827;
        }
        :is(.dark) .zbx-ticket-col { color: #f9fafb; }

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

    </style>

    <div class="zbx-page-stack">

        @php
            $tickets = $this->tickets();
            $filterOptions = $this->filterOptions();
            $count = count($tickets);
        @endphp

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
                
                <div class="zbx-toolbar-select">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="stateTypeFilter">
                            @foreach($filterOptions['state_types'] as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            </div>
            
            <div class="zbx-toolbar-count">
                Showing {{ $count }} tickets
            </div>
        </div>

        <div class="zbx-table-container">
            @if(empty($tickets))
                <div class="zbx-empty-state">
                    @if(empty($this->search) && $this->linkFilter === 'all' && empty($this->stateTypeFilter))
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
                            <tr class="zbx-problem-row">
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
                                        <br><span style="font-size: 0.7rem; color: #6b7280;">Cust: {{ $ticket['CustomerUserID'] }}</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $ticket['State'] }} 
                                    @if(!empty($ticket['StateType']))
                                        <span style="font-size: 0.7rem; color: #6b7280;">({{ $ticket['StateType'] }})</span>
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
        </div>
    </div>
</x-filament-panels::page>
