<x-filament-panels::page class="zbx-current-problems-page zbx-ticket-workspace" max-width="full">
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
            background-color: var(--zbx-table-bg, #ffffff);
            border: 1px solid var(--zbx-table-border, #e5e7eb);
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
            color: var(--zbx-table-text, #111827);
        }

        .zbx-dropdown-item:hover {
            background-color: var(--zbx-table-hover, #f3f4f6);
        }

        .zbx-toolbar-count {
            font-size: 0.875rem;
            color: #4b5563;
            font-weight: 500;
        }
        :is(.dark) .zbx-toolbar-count { color: #d1d5db; }

        /* Presets */
        .zbx-preset-row {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }

        .zbx-preset-btn {
            background: var(--zbx-table-bg);
            border: 1px solid var(--zbx-table-border);
            color: var(--zbx-table-text);
            padding: 4px 10px;
            font-size: 0.8125rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease-in-out;
            font-weight: 500;
        }

        .zbx-preset-btn:hover {
            background: var(--zbx-table-hover);
        }

        .zbx-preset-btn.active {
            background: var(--primary-600, #2563eb);
            color: #ffffff;
            border-color: var(--primary-600, #2563eb);
        }

        :is(.dark) .zbx-preset-btn.active {
            background: var(--primary-500, #3b82f6);
            border-color: var(--primary-500, #3b82f6);
        }

        /* Workspace Variables Scope */
        .zbx-ticket-workspace {
            --zbx-table-bg: var(--color-white);
            --zbx-table-head-bg: var(--gray-50);
            --zbx-table-border: var(--gray-200);
            --zbx-table-text: var(--gray-950);
            --zbx-table-muted: var(--gray-500);
            --zbx-table-hover: #eaf3ff;
        }
        :is(.dark) .zbx-ticket-workspace {
            --zbx-table-bg: var(--gray-900);
            --zbx-table-head-bg: #ffffff0d;
            --zbx-table-border: #ffffff0d;
            --zbx-table-text: var(--color-white);
            --zbx-table-muted: var(--gray-400);
            --zbx-table-hover: #ffffff0d;
        }

        /* Table Section */
        .zbx-table-container {
            background-color: var(--zbx-table-bg);
            border: 1px solid var(--zbx-table-border);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            overflow-x: auto;
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

        @media (max-width: 1500px) {
            .zbx-ticket-workspace .zbx-col-ticket-number {
                display: none;
            }
        }

        @media (max-width: 1350px) {
            .zbx-ticket-workspace .zbx-col-priority,
            .zbx-ticket-workspace .zbx-col-articles {
                display: none;
            }
        }

        @media (max-width: 850px) {
            .zbx-ticket-workspace .zbx-col-owner,
            .zbx-ticket-workspace .zbx-col-state {
                display: none;
            }
        }

        @media (max-width: 500px) {
            .zbx-ticket-workspace .zbx-col-changed {
                display: none;
            }
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

        @if(config('znuny.closed_ticket_status_panel_enabled'))
            @php
                $syncMeta = app(\App\Services\Znuny\ClosedTicketCacheService::class)->getMetadata();
            @endphp
            <div style="padding: 12px; margin-bottom: 8px; background-color: var(--zbx-table-bg); border: 1px solid var(--zbx-table-border); border-radius: 8px; font-size: 0.8125rem;">
                <div style="font-weight: 600; margin-bottom: 4px;">Recent Closed Ticket Cache Status</div>
                @if(Cache::has('znuny:closed_ticket:sync:lock'))
                    <div style="color: #ea580c; font-weight: 500; margin-bottom: 8px;">Sync is currently running.</div>
                @endif
                @if(empty($syncMeta))
                    <div style="color: var(--zbx-table-muted);">Recent closed ticket cache has not completed a full sync yet.</div>
                @else
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 8px; color: var(--zbx-table-text);">
                        <div><span style="color: var(--zbx-table-muted);">Status:</span> {{ $syncMeta['integrity_status'] ?? 'N/A' }}</div>
                        <div><span style="color: var(--zbx-table-muted);">Window Days:</span> {{ $syncMeta['window_days'] ?? 'N/A' }}</div>
                        <div><span style="color: var(--zbx-table-muted);">Retention Days:</span> {{ $syncMeta['retention_days'] ?? 'N/A' }}</div>
                        <div><span style="color: var(--zbx-table-muted);">Last Mode:</span> {{ $syncMeta['last_mode'] ?? 'N/A' }}</div>
                        <div><span style="color: var(--zbx-table-muted);">Last Reason:</span> {{ $syncMeta['last_reason'] ?? 'N/A' }}</div>
                        <div><span style="color: var(--zbx-table-muted);">Last Small Completed At:</span> {{ app(\App\Services\Support\DateTimeDisplayService::class)->formatDateTime($syncMeta['last_small_completed_at'] ?? null) ?? 'N/A' }}</div>
                        <div><span style="color: var(--zbx-table-muted);">Last Full Completed At:</span> {{ app(\App\Services\Support\DateTimeDisplayService::class)->formatDateTime($syncMeta['last_full_completed_at'] ?? null) ?? 'N/A' }}</div>
                        <div><span style="color: var(--zbx-table-muted);">Oldest Loaded Closed At:</span> {{ app(\App\Services\Support\DateTimeDisplayService::class)->formatDateTime($syncMeta['oldest_loaded_closed_at'] ?? null) ?? 'N/A' }}</div>
                        <div><span style="color: var(--zbx-table-muted);">Newest Loaded Closed At:</span> {{ app(\App\Services\Support\DateTimeDisplayService::class)->formatDateTime($syncMeta['newest_loaded_closed_at'] ?? null) ?? 'N/A' }}</div>
                        <div><span style="color: var(--zbx-table-muted);">Last Run Started At:</span> {{ app(\App\Services\Support\DateTimeDisplayService::class)->formatDateTime($syncMeta['last_run_started_at'] ?? null) ?? 'N/A' }}</div>
                        <div><span style="color: var(--zbx-table-muted);">Last Run Completed At:</span> {{ app(\App\Services\Support\DateTimeDisplayService::class)->formatDateTime($syncMeta['last_run_completed_at'] ?? null) ?? 'N/A' }}</div>
                        @if(!empty($syncMeta['last_error']))
                            <div style="color: #dc2626; grid-column: 1 / -1;"><span style="color: var(--zbx-table-muted);">Last Error:</span> {{ $syncMeta['last_error'] }}</div>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        <div class="zbx-preset-row">
            <button type="button"
                    wire:click="applyStatePreset('open')"
                    class="zbx-preset-btn {{ $this->activeStatePreset() === 'open' ? 'active' : '' }}">
                Open
            </button>
            <button type="button"
                    wire:click="applyStatePreset('closed')"
                    class="zbx-preset-btn {{ $this->activeStatePreset() === 'closed' ? 'active' : '' }}">
                Closed
            </button>
            <button type="button"
                    wire:click="applyStatePreset('merged')"
                    class="zbx-preset-btn {{ $this->activeStatePreset() === 'merged' ? 'active' : '' }}">
                Merged
            </button>
            <button type="button"
                    wire:click="applyStatePreset('all')"
                    class="zbx-preset-btn {{ $this->activeStatePreset() === 'all' ? 'active' : '' }}">
                All
            </button>
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
                            @foreach(app(\App\Support\Pagination\PaginationSettings::class)->perPageOptions() as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
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
                            <th style="width: 140px;" class="zbx-col-ticket-number">
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
                            <th class="zbx-col-owner">
                                <button type="button" class="zbx-th-button" wire:click="sortBy('Owner')">
                                    Owner
                                    @if($this->sortField === 'Owner')
                                        <x-filament::icon icon="{{ $this->sortDirection === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="zbx-sort-icon active" />
                                    @endif
                                </button>
                            </th>
                            <th class="zbx-col-state">
                                <button type="button" class="zbx-th-button" wire:click="sortBy('State')">
                                    State / Type
                                    @if($this->sortField === 'State')
                                        <x-filament::icon icon="{{ $this->sortDirection === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="zbx-sort-icon active" />
                                    @endif
                                </button>
                            </th>
                            <th class="zbx-col-priority">
                                <button type="button" class="zbx-th-button" wire:click="sortBy('Priority')">
                                    Priority
                                    @if($this->sortField === 'Priority')
                                        <x-filament::icon icon="{{ $this->sortDirection === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="zbx-sort-icon active" />
                                    @endif
                                </button>
                            </th>
                            <th style="text-align: right;" class="zbx-col-articles">
                                Articles
                            </th>
                            <th style="text-align: right;" class="zbx-col-changed">
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
                            <tr class="zbx-problem-row" style="cursor: pointer;" wire:click="mountAction('viewTicket', { znuny_ticket_id: {{ $ticket['TicketID'] }} })">
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
                                <td class="zbx-ticket-col zbx-col-ticket-number">
                                    {{ $ticket['TicketNumber'] }}
                                </td>
                                <td>
                                    {{ $ticket['Title'] }}
                                </td>
                                <td>
                                    {{ $ticket['Queue'] }}
                                </td>
                                <td class="zbx-col-owner">
                                    {{ $filterOptions['agent_name_map'][$ticket['OwnerID']] ?? $ticket['Owner'] }}
                                    @if(!empty($ticket['CustomerUserID']))
                                        <br><span class="zbx-muted-text" style="font-size: 0.7rem;">Cust: {{ $ticket['CustomerUserID'] }}</span>
                                    @endif
                                </td>
                                <td class="zbx-col-state">
                                    {{ $ticket['State'] }}
                                    @if(!empty($ticket['StateType']))
                                        <span class="zbx-muted-text" style="font-size: 0.7rem;">({{ $ticket['StateType'] }})</span>
                                    @endif
                                </td>
                                <td class="zbx-col-priority">
                                    {{ $ticket['Priority'] }}
                                </td>
                                <td style="text-align: right;" class="zbx-col-articles">
                                    {{ $ticket['ArticleCount'] }}
                                </td>
                                <td style="text-align: right; white-space: nowrap;" class="zbx-col-changed">
                                    @if(!empty($ticket['Changed']))
                                        @php
                                            $parsedChanged = \App\Filament\Support\TicketDetailsPayload::parseZnunyTimestamp($ticket['Changed']);
                                            $changedStr = '-';
                                            if ($parsedChanged) {
                                                $changedAge = $parsedChanged->diffInSeconds(now());
                                                $changedStr = app(\App\Services\Zabbix\ZabbixProblemFormatter::class)->formatAge($changedAge);
                                            }
                                        @endphp
                                        {{ $changedStr }}
                                    @else
                                        -
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

    <x-filament-actions::modals />
</x-filament-panels::page>
