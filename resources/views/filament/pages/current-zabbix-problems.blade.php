<x-filament-panels::page class="zbx-current-problems-page" max-width="full">
    <style>
        [x-cloak] { display: none !important; }

        /* Page Width overrides */
        .zbx-current-problems-page {
            width: 100%;
        }

        .fi-main:has(.zbx-current-problems-page) .fi-page-content {
            max-width: none !important;
        }

        .zbx-current-problems-page .zbx-page-stack,
        .zbx-current-problems-page .zbx-table-container,
        .zbx-current-problems-page .zbx-status-section,
        .zbx-current-problems-page .zbx-toolbar {
            width: 100%;
            max-width: none;
        }



        .zbx-page-stack {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        /* Status Section */
        .zbx-status-section {
            background-color: var(--bg-color, #ffffff);
            border: 1px solid var(--border-color, #e5e7eb);
            border-radius: 12px;
            padding: 12px 18px;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        :is(.dark) .zbx-status-section {
            --bg-color: #111827;
            --border-color: rgba(255, 255, 255, 0.1);
        }

        .zbx-status-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 16px 32px;
            margin-bottom: 12px;
        }

        .zbx-metric {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .zbx-metric-label {
            font-size: 0.875rem;
            color: #6b7280;
        }
        :is(.dark) .zbx-metric-label {
            color: #9ca3af;
        }

        .zbx-metric-value {
            font-size: 1.125rem;
            font-weight: 500;
            color: #111827;
        }
        :is(.dark) .zbx-metric-value {
            color: #f9fafb;
        }

        .zbx-status-success { color: #059669; }
        :is(.dark) .zbx-status-success { color: #34d399; }

        .zbx-status-danger { color: #dc2626; }
        :is(.dark) .zbx-status-danger { color: #f87171; }

        .zbx-exclusion-breakdown {
            font-size: 0.875rem;
            color: #4b5563;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border-color, #e5e7eb);
        }
        :is(.dark) .zbx-exclusion-breakdown {
            color: #9ca3af;
        }

        .zbx-limit-info {
            font-size: 0.75rem;
            color: #9ca3af;
            margin-top: 4px;
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

        .zbx-toolbar-search {
            flex: 1;
            max-width: 400px;
        }

        .zbx-toolbar-count {
            font-size: 0.875rem;
            color: #4b5563;
            font-weight: 500;
        }
        :is(.dark) .zbx-toolbar-count {
            color: #d1d5db;
        }

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
        .zbx-th-button:hover {
            color: #111827;
        }
        :is(.dark) .zbx-th-button:hover {
            color: #ffffff;
        }

        .zbx-sort-icon {
            width: 14px;
            height: 14px;
            color: #9ca3af;
        }
        .zbx-sort-icon.active {
            color: #3b82f6;
        }

        .zbx-table tbody {
            border-bottom: 1px solid var(--border-color, #e5e7eb);
        }

        .zbx-problem-row {
            cursor: pointer;
        }
        .zbx-problem-row td {
            padding: 2px 10px;
            color: #4b5563;
        }
        .zbx-problem-row > td {
            transition: background-color 0.12s ease-in-out;
        }
        .zbx-table tbody tr.zbx-problem-row:hover > td {
            background-color: #eaf3ff !important;
        }
        :is(.dark) .zbx-table tbody tr.zbx-problem-row:hover > td {
            background-color: rgba(59, 130, 246, 0.14) !important;
        }
        :is(.dark) .zbx-problem-row td {
            color: #d1d5db;
        }
        .zbx-problem-row td.zbx-host-col {
            font-weight: 600;
            color: #111827;
            width: 25%;
        }
        :is(.dark) .zbx-problem-row td.zbx-host-col {
            color: #f9fafb;
        }

        .zbx-details-row {
            background-color: #f9fafb;
        }
        :is(.dark) .zbx-details-row {
            background-color: rgba(255, 255, 255, 0.02);
        }

        .zbx-details {
            padding: 12px 16px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }
        @media (min-width: 1024px) {
            .zbx-details {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .zbx-detail-block h4 {
            font-weight: 600;
            color: #111827;
            margin-bottom: 8px;
            font-size: 0.875rem;
        }
        :is(.dark) .zbx-detail-block h4 {
            color: #f9fafb;
        }

        .zbx-detail-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 2px;
            font-size: 0.8125rem;
        }

        .zbx-detail-list li {
            color: #4b5563;
        }
        :is(.dark) .zbx-detail-list li {
            color: #9ca3af;
        }

        .zbx-detail-list strong {
            color: #374151;
            font-weight: 500;
            margin-right: 4px;
        }
        :is(.dark) .zbx-detail-list strong {
            color: #d1d5db;
        }

        /* Tags array */
        .zbx-tag {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 6px;
            background-color: #f3f4f6;
            border: 1px solid #e5e7eb;
            font-size: 0.75rem;
            color: #4b5563;
            margin-right: 4px;
            margin-bottom: 4px;
        }
        :is(.dark) .zbx-tag {
            background-color: #1f2937;
            border-color: #374151;
            color: #d1d5db;
        }
        .zbx-tag-value {
            margin-left: 4px;
            color: #111827;
            font-weight: 500;
        }
        :is(.dark) .zbx-tag-value {
            color: #f9fafb;
        }

                /* Ticket Actions */
        .zbx-ticket-panel {
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid var(--border-color, #e5e7eb);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
        }
        :is(.dark) .zbx-ticket-panel {
            border-top-color: rgba(255, 255, 255, 0.1);
        }

        .zbx-ticket-linked {
            background-color: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
        }
        :is(.dark) .zbx-ticket-linked {
            background-color: rgba(59, 130, 246, 0.1);
            border-color: rgba(59, 130, 246, 0.2);
        }

                /* Severity Pills */
        .zbx-severity {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 1px 6px;
            border-radius: 4px;
            font-size: 0.72rem;
            font-weight: 600;
            line-height: 1.25;
            white-space: nowrap;
        }
        .zbx-severity-0 { background: #f3f4f6; color: #4b5563; border: 1px solid #e5e7eb; } /* Not classified */
        .zbx-severity-1 { background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; } /* Information */
        .zbx-severity-2 { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; } /* Warning */
        .zbx-severity-3 { background: #ffedd5; color: #9a3412; border: 1px solid #fed7aa; } /* Average */
        .zbx-severity-4 { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; } /* High */
        .zbx-severity-5 { background: #fecaca; color: #7f1d1d; border: 1px solid #fca5a5; } /* Disaster */

        :is(.dark) .zbx-severity-0 { background: #374151; color: #d1d5db; border-color: #4b5563; }
        :is(.dark) .zbx-severity-1 { background: #1e3a8a; color: #bfdbfe; border-color: #1e40af; }
        :is(.dark) .zbx-severity-2 { background: #78350f; color: #fde68a; border-color: #92400e; }
        :is(.dark) .zbx-severity-3 { background: #7c2d12; color: #fed7aa; border-color: #9a3412; }
        :is(.dark) .zbx-severity-4 { background: #7f1d1d; color: #fecaca; border-color: #991b1b; }
        :is(.dark) .zbx-severity-5 { background: #450a0a; color: #fca5a5; border-color: #7f1d1d; }

        /* Modal specific styles */
        .zbx-ticket-modal-section {
            margin-bottom: 24px;
        }
        .zbx-ticket-modal-section-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        :is(.dark) .zbx-ticket-modal-section-title {
            color: #9ca3af;
        }
        .zbx-ticket-summary-card {
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
            font-size: 0.875rem;
        }
        :is(.dark) .zbx-ticket-summary-card {
            background-color: rgba(255, 255, 255, 0.02);
            border-color: rgba(255, 255, 255, 0.1);
        }
        .zbx-ticket-summary-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px 24px;
        }
        .zbx-ticket-summary-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .zbx-ticket-summary-item.full-width {
            grid-column: 1 / -1;
        }
        .zbx-ticket-summary-label {
            color: #6b7280;
            font-weight: 500;
        }
        :is(.dark) .zbx-ticket-summary-label {
            color: #9ca3af;
        }
        .zbx-ticket-summary-value {
            color: #111827;
        }
        :is(.dark) .zbx-ticket-summary-value {
            color: #f9fafb;
        }

                /* Form fields spacing */
        .zbx-ticket-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .zbx-ticket-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: #374151;
        }

        :is(.dark) .zbx-ticket-label {
            color: #d1d5db;
        }

        .zbx-ticket-field-stack {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .zbx-ticket-search-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: flex-start;
        }
        .zbx-ticket-search-input {
            flex: 1;
            min-width: 200px;
        }

        .zbx-ticket-fixed-values {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 8px;
        }
        .zbx-ticket-chip {
            display: inline-flex;
            align-items: center;
            background-color: #f3f4f6;
            border: 1px solid #e5e7eb;
            border-radius: 9999px;
            padding: 2px 10px;
            font-size: 0.75rem;
        }
        :is(.dark) .zbx-ticket-chip {
            background-color: #374151;
            border-color: #4b5563;
        }
        .zbx-ticket-chip-label {
            color: #6b7280;
            margin-right: 6px;
        }
        :is(.dark) .zbx-ticket-chip-label {
            color: #9ca3af;
        }
        .zbx-ticket-chip-value {
            color: #111827;
            font-weight: 500;
        }
        :is(.dark) .zbx-ticket-chip-value {
            color: #f9fafb;
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
        :is(.dark) .zbx-empty-state h3 {
            color: #f9fafb;
        }
        .zbx-empty-state p {
            color: #6b7280;
            font-size: 0.875rem;
        }
        :is(.dark) .zbx-empty-state p {
            color: #9ca3af;
        }
    </style>

    <div wire:poll.{{ $this->getRefreshIntervalString() }} class="zbx-page-stack">

        {{-- Status Section --}}
        @php
            $lastPoll = $this->lastPoll;
            $status = $lastPoll['status'] ?? 'N/A';
            $isFailed = $status === 'failed';
            $cached = $lastPoll['cached_count'] ?? $lastPoll['problem_count'] ?? 0;
            $fetched = $lastPoll['fetched_count'] ?? 0;
            $ttl = $lastPoll['ttl_seconds'] ?? 0;
            $limit = $lastPoll['limit'] ?? 'N/A';
            $error = $lastPoll['error'] ?? null;

            $excludedFilters = $lastPoll['excluded_count'] ?? 0;
            $excludedHosts = $lastPoll['disabled_hosts_excluded_count'] ?? 0;
            $excludedTriggers = $lastPoll['disabled_triggers_excluded_count'] ?? 0;
            $excludedItems = $lastPoll['disabled_items_excluded_count'] ?? 0;
            $excludedDeps = $lastPoll['dependency_covered_excluded_count'] ?? 0;
            $excludedSupp = $lastPoll['suppressed_excluded_count'] ?? 0;

            $totalExcluded = $excludedFilters + $excludedHosts + $excludedTriggers + $excludedItems + $excludedDeps + $excludedSupp;

            $polledAt = $lastPoll['polled_at'] ?? null;
            $ageText = 'N/A';
            if ($polledAt) {
                try {
                    $ageSeconds = \Carbon\Carbon::parse($polledAt)->diffInSeconds(now());
                    $ageText = $this->formatAge($ageSeconds) . ' ago';
                } catch (\Exception $e) {
                    $ageText = 'N/A';
                }
            }
        @endphp

        <div class="zbx-status-section">
            <h3 class="font-semibold text-lg mb-4 text-gray-900 dark:text-white">Polling status</h3>
            <div class="zbx-status-grid">
                <div class="zbx-metric">
                    <span class="zbx-metric-label">Status</span>
                    <span class="zbx-metric-value {{ $isFailed ? 'zbx-status-danger' : 'zbx-status-success' }}">
                        {{ ucfirst($status) }}
                    </span>
                </div>
                <div class="zbx-metric">
                    <span class="zbx-metric-label">Current problems</span>
                    <span class="zbx-metric-value">{{ $cached }}</span>
                </div>
                <div class="zbx-metric">
                    <span class="zbx-metric-label">Fetched</span>
                    <span class="zbx-metric-value">{{ $fetched }}</span>
                    @if($limit !== 'N/A')
                        <span class="zbx-limit-info">Limit: {{ $limit }}</span>
                    @endif
                </div>
                <div class="zbx-metric">
                    <span class="zbx-metric-label">Excluded total</span>
                    <span class="zbx-metric-value">{{ $totalExcluded }}</span>
                </div>
                <div class="zbx-metric">
                    <span class="zbx-metric-label">Last poll</span>
                    <span class="zbx-metric-value">{{ $ageText }}</span>
                </div>
                <div class="zbx-metric">
                    <span class="zbx-metric-label">Cache TTL</span>
                    <span class="zbx-metric-value">{{ $ttl }}s</span>
                </div>
            </div>

            <div class="zbx-exclusion-breakdown">
                <strong>Excluded Breakdown:</strong>
                Hosts {{ $excludedHosts }} &middot;
                Triggers {{ $excludedTriggers }} &middot;
                Items {{ $excludedItems }} &middot;
                Dependencies {{ $excludedDeps }} &middot;
                Suppressed {{ $excludedSupp }} &middot;
                Filters {{ $excludedFilters }}
            </div>

            @if($isFailed && $error)
                <div class="mt-4 text-sm zbx-status-danger bg-red-50 dark:bg-red-900/20 p-3 rounded-md border border-red-200 dark:border-red-800">
                    <strong>Error:</strong> {{ $error }}
                </div>
            @endif
        </div>

        {{-- Toolbar --}}
        @php
            $problems = $this->problems;
            $showingCount = count($problems);
            $totalCount = $this->totalCachedCount;
        @endphp
        <div class="zbx-toolbar">
            <div class="zbx-toolbar-search">
                <x-filament::input.wrapper icon="heroicon-m-magnifying-glass">
                    <x-filament::input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search host or problem name..."
                    />
                </x-filament::input.wrapper>
            </div>
            <div class="zbx-toolbar-count">
                @if(empty($this->search))
                    Showing {{ $totalCount }} problems
                @else
                    Showing {{ $showingCount }} of {{ $totalCount }} problems
                @endif
            </div>
        </div>

        {{-- Table --}}
        @php
            $eventIds = collect($problems)->pluck('eventid')->filter()->toArray();
            $linkedTickets = \App\Models\ZabbixTicket::whereIn('zabbix_event_id', $eventIds)->get()->keyBy('zabbix_event_id');
            $canCreateTicket = in_array(auth()->user()->role, ['admin', 'operator'], true);
        @endphp
        <div class="zbx-table-container">
            @if(empty($problems))
                <div class="zbx-empty-state">
                    @if($status === 'N/A')
                        <x-filament::icon icon="heroicon-o-clock" class="zbx-empty-icon" />
                        <h3>No poll data yet</h3>
                        <p>Waiting for the first Zabbix poll to complete.</p>
                    @elseif(!empty($this->search))
                        <x-filament::icon icon="heroicon-o-magnifying-glass" class="zbx-empty-icon" />
                        <h3>No matching problems</h3>
                        <p>Try adjusting your search query.</p>
                    @else
                        <x-filament::icon icon="heroicon-o-check-circle" class="zbx-empty-icon zbx-status-success" />
                        <h3>All clear</h3>
                        <p>No active Zabbix problems found in cache.</p>
                    @endif
                </div>
            @else
                <table class="zbx-table" x-data="{ expandedEventIds: [] }">
                    <thead>
                        <tr>
                            <th style="width: 42px;"></th>
                            <th style="width: 130px;">
                                <button type="button" class="zbx-th-button" wire:click="sortBy('severity')">
                                    Severity
                                    @if($this->sortField === 'severity')
                                        <x-filament::icon icon="{{ $this->sortDirection === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="zbx-sort-icon active" />
                                    @endif
                                </button>
                            </th>
                            <th>
                                <button type="button" class="zbx-th-button" wire:click="sortBy('host')">
                                    Host
                                    @if($this->sortField === 'host')
                                        <x-filament::icon icon="{{ $this->sortDirection === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="zbx-sort-icon active" />
                                    @endif
                                </button>
                            </th>
                            <th>
                                <button type="button" class="zbx-th-button" wire:click="sortBy('problem')">
                                    Problem
                                    @if($this->sortField === 'problem')
                                        <x-filament::icon icon="{{ $this->sortDirection === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="zbx-sort-icon active" />
                                    @endif
                                </button>
                            </th>
                            <th style="width: 110px; text-align: right;">
                                <button type="button" class="zbx-th-button" style="justify-content: flex-end;" wire:click="sortBy('age')">
                                    Age
                                    @if($this->sortField === 'age')
                                        <x-filament::icon icon="{{ $this->sortDirection === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="zbx-sort-icon active" />
                                    @endif
                                </button>
                            </th>
                        </tr>
                    </thead>

                    @foreach($problems as $problem)
                        @php
                            $severityValue = (int) ($problem['severity'] ?? 0);
                            $severityColor = $this->getSeverityColor($severityValue);
                            $severityLabel = $problem['severity_label'] ?? $this->getSeverityFallback($severityValue);
                            $ageSeconds = $this->getProblemAgeSeconds($problem);
                            $eventId = (string) ($problem['eventid'] ?? $problem['objectid'] ?? $problem['triggerid'] ?? $loop->index);
                        @endphp
                        <tbody wire:key="zbx-problem-{{ $eventId }}">
                            <tr class="zbx-problem-row" @click="
                                expandedEventIds.includes('{{ $eventId }}')
                                    ? expandedEventIds = expandedEventIds.filter(id => id !== '{{ $eventId }}')
                                    : expandedEventIds.push('{{ $eventId }}')
                            ">
                                <td>
                                    <button @click.stop="
                                        expandedEventIds.includes('{{ $eventId }}')
                                            ? expandedEventIds = expandedEventIds.filter(id => id !== '{{ $eventId }}')
                                            : expandedEventIds.push('{{ $eventId }}')
                                    " type="button" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 focus:outline-none">
                                        <x-filament::icon x-show="!expandedEventIds.includes('{{ $eventId }}')" icon="heroicon-m-chevron-right" class="w-5 h-5" />
                                        <x-filament::icon x-show="expandedEventIds.includes('{{ $eventId }}')" icon="heroicon-m-chevron-down" class="w-5 h-5" x-cloak />
                                    </button>
                                </td>
                                <td>
                                    <span class="zbx-severity zbx-severity-{{ $severityValue }}">
                                        {{ $severityLabel }}
                                    </span>
                                </td>
                                <td class="zbx-host-col">
                                    {{ $problem['host_name'] ?? 'Unknown host' }}
                                </td>
                                <td>
                                    {{ $problem['name'] ?? '' }}
                                </td>
                                <td style="text-align: right;">
                                    {{ $this->formatAge($ageSeconds) }}
                                </td>
                            </tr>

                            <tr class="zbx-details-row" x-show="expandedEventIds.includes('{{ $eventId }}')" x-cloak>
                                <td colspan="5" style="padding: 0;">
                                    <div class="zbx-details">

                                        <div class="zbx-detail-block">
                                            <h4>Problem</h4>
                                            <ul class="zbx-detail-list">
                                                <li><strong>Event ID:</strong> {{ $problem['eventid'] ?? 'N/A' }}</li>
                                                <li><strong>Object ID:</strong> {{ $problem['objectid'] ?? 'N/A' }}</li>
                                                <li><strong>Started At:</strong> {{ $problem['started_at'] ?? 'N/A' }}</li>
                                                <li><strong>Current Age:</strong> {{ $this->formatAge($ageSeconds) }}</li>
                                                <li><strong>Acknowledged:</strong> {{ !empty($problem['acknowledged']) && $problem['acknowledged'] != 0 ? 'Yes' : 'No' }}</li>
                                                <li><strong>Suppressed:</strong> {{ !empty($problem['suppressed']) && $problem['suppressed'] != 0 ? 'Yes' : 'No' }}</li>
                                                <li><strong>Severity:</strong> <span class="zbx-severity zbx-severity-{{ $severityValue }}">{{ $severityLabel }}</span></li>
                                            </ul>
                                        </div>

                                        <div class="zbx-detail-block">
                                            <h4>Host</h4>
                                            @if(isset($problem['hosts']) && is_array($problem['hosts']) && count($problem['hosts']) > 0)
                                                @foreach($problem['hosts'] as $host)
                                                    <ul class="zbx-detail-list" style="margin-bottom: 8px;">
                                                        <li><strong>Display Name:</strong> {{ $host['name'] ?? 'N/A' }}</li>
                                                        <li><strong>Technical Name:</strong> {{ $host['host'] ?? 'N/A' }}</li>
                                                        <li><strong>Host ID:</strong> {{ $host['hostid'] ?? 'N/A' }}</li>
                                                        <li><strong>Host Status:</strong> {{ isset($host['status']) ? ($host['status'] == 0 ? 'Monitored (0)' : 'Disabled (1)') : 'N/A' }}</li>
                                                    </ul>
                                                @endforeach
                                            @else
                                                <div class="text-sm text-gray-500">No host context available</div>
                                            @endif
                                        </div>

                                        <div class="zbx-ticket-panel" style="grid-column: 1 / -1;">
                                            @php
                                                $linkedTicket = $linkedTickets[$problem['eventid'] ?? ''] ?? null;
                                            @endphp
                                            @if($linkedTicket)
                                                <div class="zbx-ticket-linked">
                                                    <div>
                                                        <span class="font-medium text-blue-900 dark:text-blue-200">Ticket already linked:</span>
                                                        <a href="{{ app(\App\Services\Znuny\ZnunyClient::class)->ticketUrl($linkedTicket->znuny_ticket_id) }}" target="_blank" class="text-blue-700 dark:text-blue-400 hover:underline font-bold ml-1">
                                                            {{ $linkedTicket->znuny_ticket_number }}
                                                        </a>
                                                    </div>
                                                    <x-filament::button tag="a" href="{{ app(\App\Services\Znuny\ZnunyClient::class)->ticketUrl($linkedTicket->znuny_ticket_id) }}" target="_blank" color="info" size="sm" icon="heroicon-o-arrow-top-right-on-square">
                                                        Open Ticket
                                                    </x-filament::button>
                                                </div>
                                            @elseif($canCreateTicket)
                                                <x-filament::button wire:click="openCreateTicketModal('{{ $problem['eventid'] }}')" icon="heroicon-o-ticket">
                                                    Create ticket
                                                </x-filament::button>
                                            @endif
                                        </div>

                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            @endif
        </div>
    </div>

    <x-filament::modal id="create-ticket-modal" width="2xl">
        <x-slot name="heading">
            Create Znuny Ticket
        </x-slot>
        <x-slot name="description">
            Preflight check and validation before creating a ticket for this Zabbix problem.
        </x-slot>

        @if($ticketModalProblem)
            <div class="mb-2">
                @php
                    $modalSevValue = (int) ($ticketModalProblem['severity'] ?? 0);
                    $modalSevColor = $this->getSeverityColor($modalSevValue);
                    $modalSevLabel = $ticketModalProblem['severity_label'] ?? $this->getSeverityFallback($modalSevValue);
                @endphp

                {{-- Problem summary --}}
                <div class="zbx-ticket-modal-section">
                    <div class="zbx-ticket-modal-section-title">Problem summary</div>
                    <div class="zbx-ticket-summary-card">
                        <div class="zbx-ticket-summary-grid">
                            <div class="zbx-ticket-summary-item">
                                <span class="zbx-ticket-summary-label">Host</span>
                                <span class="zbx-ticket-summary-value">{{ $ticketModalProblem['host_name'] ?? 'N/A' }}</span>
                            </div>
                            <div class="zbx-ticket-summary-item">
                                <span class="zbx-ticket-summary-label">Event ID</span>
                                <span class="zbx-ticket-summary-value">{{ $ticketModalProblem['eventid'] ?? 'N/A' }}</span>
                            </div>
                            <div class="zbx-ticket-summary-item full-width">
                                <span class="zbx-ticket-summary-label">Problem</span>
                                <span class="zbx-ticket-summary-value">{{ $ticketModalProblem['name'] ?? 'N/A' }}</span>
                            </div>
                            <div class="zbx-ticket-summary-item">
                                <span class="zbx-ticket-summary-label">Severity</span>
                                <span class="zbx-ticket-summary-value">
                                    <x-filament::badge :color="$modalSevColor">
                                        {{ $modalSevLabel }}
                                    </x-filament::badge>
                                </span>
                            </div>
                            <div class="zbx-ticket-summary-item">
                                <span class="zbx-ticket-summary-label">Started</span>
                                <span class="zbx-ticket-summary-value">{{ $ticketModalProblem['started_at'] ?? 'N/A' }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Default resolution --}}
                @if(!empty($ticketDefaultWarnings))
                    <div class="zbx-ticket-modal-section">
                        <div class="zbx-ticket-modal-section-title">Default resolution warnings</div>
                        <div class="bg-warning-50 dark:bg-warning-900/20 p-4 rounded-lg border border-warning-200 dark:border-warning-800 text-warning-700 dark:text-warning-400 text-sm">
                            <ul class="list-disc pl-5">
                                @foreach($ticketDefaultWarnings as $warn)
                                    <li>{{ $warn }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif

                {{-- Ticket fields --}}
                <div class="zbx-ticket-modal-section">
                    <div class="zbx-ticket-modal-section-title">Ticket fields</div>
                    <div class="zbx-ticket-field-stack">
                        <div class="zbx-ticket-field">
                            <label class="zbx-ticket-label">Owner <span class="text-danger-600">*</span></label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model="ticketOwnerId">
                                    <option value="">Select an owner</option>
                                    @foreach($ticketOwnerOptions as $id => $label)
                                        <option value="{{ $id }}">{{ $label }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>

                        <div class="zbx-ticket-field">
                            <label class="zbx-ticket-label">Queue <span class="text-danger-600">*</span></label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model="ticketQueue">
                                    <option value="">Select a queue</option>
                                    @foreach($ticketQueueOptions as $name => $label)
                                        <option value="{{ $name }}">{{ $label }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>

                        <div class="zbx-ticket-field">
                            <label class="zbx-ticket-label">CustomerUser <span class="text-danger-600">*</span></label>

                            <div class="zbx-ticket-search-row">
                                <x-filament::input.wrapper class="zbx-ticket-search-input">
                                    <x-filament::input type="text" wire:model="ticketCustomerUserSearch" placeholder="Search CustomerUser..." wire:keydown.enter="searchTicketCustomerUsers" />
                                </x-filament::input.wrapper>
                                <x-filament::button color="gray" wire:click="searchTicketCustomerUsers">Search</x-filament::button>
                            </div>
                            <div class="text-xs text-gray-500 mt-1 mb-2">Search by login, customer ID, name, or email.</div>

                            @if(!empty($ticketCustomerUserSearch) && empty($ticketCustomerUserOptions))
                                <div class="text-sm text-gray-500 mb-2">No matching CustomerUser found.</div>
                            @endif

                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model="ticketCustomerUser">
                                    <option value="">Select a customer user</option>
                                    @foreach($ticketCustomerUserOptions as $login => $label)
                                        <option value="{{ $login }}">{{ $label }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>

                        <div class="zbx-ticket-fixed-values">
                            <span class="text-sm font-medium text-gray-500">Fixed ticket values:</span>
                            <div class="zbx-ticket-chip">
                                <span class="zbx-ticket-chip-label">State</span>
                                <span class="zbx-ticket-chip-value">new</span>
                            </div>
                            <div class="zbx-ticket-chip">
                                <span class="zbx-ticket-chip-label">Lock</span>
                                <span class="zbx-ticket-chip-value">lock</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Preflight result --}}
                @if($ticketValidationStatus === 'success' || $ticketValidationStatus === 'error' || !empty($ticketValidationWarnings))
                    <div class="zbx-ticket-modal-section">
                        <div class="zbx-ticket-modal-section-title">Preflight result</div>

                        @if($ticketValidationStatus === 'success')
                            <div class="bg-success-50 dark:bg-success-900/20 p-4 rounded-lg border border-success-200 dark:border-success-800 text-success-700 dark:text-success-400 text-sm">
                                <div class="flex items-center gap-2 mb-1">
                                    <x-filament::icon icon="heroicon-o-check-circle" class="w-5 h-5" />
                                    <strong class="font-medium text-base">Ticket data is valid</strong>
                                </div>
                                <p class="ml-7">The selected Owner, Queue and CustomerUser passed Znuny preflight validation.</p>
                            </div>
                        @elseif($ticketValidationStatus === 'error')
                            <div class="bg-danger-50 dark:bg-danger-900/20 p-4 rounded-lg border border-danger-200 dark:border-danger-800 text-danger-700 dark:text-danger-400 text-sm">
                                <div class="flex items-center gap-2 mb-2">
                                    <x-filament::icon icon="heroicon-o-x-circle" class="w-5 h-5" />
                                    <strong class="font-medium text-base">Validation Errors</strong>
                                </div>
                                <ul class="list-disc pl-9 mt-1">
                                    @foreach($ticketValidationErrors as $err)
                                        <li>{{ $err }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if(!empty($ticketValidationWarnings))
                            <div class="bg-warning-50 dark:bg-warning-900/20 p-4 rounded-lg border border-warning-200 dark:border-warning-800 text-warning-700 dark:text-warning-400 text-sm mt-3">
                                <div class="flex items-center gap-2 mb-2">
                                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-5 h-5" />
                                    <strong class="font-medium text-base">Validation Warnings</strong>
                                </div>
                                <ul class="list-disc pl-9 mt-1">
                                    @foreach($ticketValidationWarnings as $warn)
                                        <li>{{ $warn }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        {{-- Actions --}}
        <x-slot name="footer">
            <div class="flex justify-between items-center w-full">
                <x-filament::button color="gray" wire:click="closeCreateTicketModal">
                    Cancel
                </x-filament::button>
                <x-filament::button wire:click="validateTicketData" wire:loading.attr="disabled" wire:target="validateTicketData">
                    <span wire:loading.remove wire:target="validateTicketData">Validate ticket data</span>
                    <span wire:loading wire:target="validateTicketData">Validating...</span>
                </x-filament::button>
            </div>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::page>
