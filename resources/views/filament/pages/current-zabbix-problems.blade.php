<x-filament-panels::page>
    <style>
        [x-cloak] { display: none !important; }
        
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
            padding: 18px 24px;
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

        .zbx-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.875rem;
        }

        .zbx-table th {
            background-color: #f9fafb;
            color: #374151;
            font-weight: 500;
            padding: 14px 16px;
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
            background-color: transparent;
            transition: background-color 0.15s ease-in-out;
            cursor: pointer;
        }
        .zbx-problem-row:hover {
            background-color: #f9fafb;
        }
        :is(.dark) .zbx-problem-row:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }

        .zbx-problem-row td {
            padding: 14px 16px;
            color: #4b5563;
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
            padding: 24px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
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
            gap: 4px;
            font-size: 0.875rem;
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
                <table class="zbx-table">
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
                        @endphp
                        <tbody x-data="{ expanded: false }">
                            <tr class="zbx-problem-row" @click="expanded = !expanded">
                                <td>
                                    <button @click.stop="expanded = !expanded" type="button" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 focus:outline-none">
                                        <x-filament::icon x-show="!expanded" icon="heroicon-m-chevron-right" class="w-5 h-5" />
                                        <x-filament::icon x-show="expanded" icon="heroicon-m-chevron-down" class="w-5 h-5" x-cloak />
                                    </button>
                                </td>
                                <td>
                                    <x-filament::badge :color="$severityColor">
                                        {{ $severityLabel }}
                                    </x-filament::badge>
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
                            
                            <tr class="zbx-details-row" x-show="expanded" x-cloak>
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
                                                <li><strong>Severity:</strong> {{ $severityValue }}</li>
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

                                        <div class="zbx-detail-block">
                                            <h4>Trigger</h4>
                                            <ul class="zbx-detail-list">
                                                @if(isset($problem['triggerid']))
                                                    <li><strong>Trigger ID:</strong> {{ $problem['triggerid'] }}</li>
                                                    <li><strong>Description:</strong> {{ $problem['trigger_description'] ?? 'N/A' }}</li>
                                                    <li><strong>Status:</strong> {{ isset($problem['trigger_status']) ? ($problem['trigger_status'] == 0 ? 'Enabled (0)' : 'Disabled (1)') : 'N/A' }}</li>
                                                    
                                                    @if(!empty($problem['trigger_dependencies']))
                                                        <li style="margin-top: 8px;"><strong>Dependencies:</strong></li>
                                                        <ul class="zbx-detail-list" style="padding-left: 12px;">
                                                            @foreach($problem['trigger_dependencies'] as $dep)
                                                                <li>ID {{ $dep['triggerid'] ?? '?' }} - {{ $dep['description'] ?? 'Unknown' }}</li>
                                                            @endforeach
                                                        </ul>
                                                    @endif

                                                    @if(!empty($problem['trigger_items']))
                                                        <li style="margin-top: 8px;"><strong>Related Items:</strong></li>
                                                        <ul class="zbx-detail-list" style="padding-left: 12px;">
                                                            @foreach($problem['trigger_items'] as $item)
                                                                <li>
                                                                    ID {{ $item['itemid'] ?? '?' }} - {{ $item['name'] ?? '?' }}
                                                                    (Status: {{ $item['status'] ?? '?' }})
                                                                    <div style="font-size: 0.75rem; color: #9ca3af;">Key: {{ $item['key_'] ?? '?' }}</div>
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    @endif
                                                @else
                                                    <li>No trigger data available</li>
                                                @endif
                                            </ul>
                                        </div>

                                        <div class="zbx-detail-block">
                                            <h4>Tags</h4>
                                            @if(!empty($problem['tags']))
                                                <div>
                                                    @foreach($problem['tags'] as $tag)
                                                        <div class="zbx-tag">
                                                            {{ $tag['tag'] ?? '' }}
                                                            @if(!empty($tag['value']))
                                                                <span class="zbx-tag-value">{{ $tag['value'] }}</span>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @else
                                                <div class="text-sm text-gray-500">No tags available</div>
                                            @endif
                                        </div>

                                        <div class="zbx-detail-block" style="grid-column: 1 / -1;">
                                            @php
                                                $linkedTicket = $linkedTickets[$problem['eventid'] ?? ''] ?? null;
                                            @endphp
                                            @if($linkedTicket)
                                                <div class="p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg flex items-center justify-between">
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
                                                <div class="mt-2">
                                                    <x-filament::button wire:click="openCreateTicketModal('{{ $problem['eventid'] }}')" icon="heroicon-o-ticket">
                                                        Create ticket
                                                    </x-filament::button>
                                                </div>
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
            <div class="mb-6 space-y-3">
                <div class="bg-gray-50 dark:bg-gray-900 p-4 rounded-lg border border-gray-200 dark:border-gray-800 text-sm">
                    <div class="grid grid-cols-2 gap-4">
                        <div><strong class="text-gray-700 dark:text-gray-300">Host:</strong> {{ $ticketModalProblem['host_name'] ?? 'N/A' }}</div>
                        <div><strong class="text-gray-700 dark:text-gray-300">Event ID:</strong> {{ $ticketModalProblem['eventid'] ?? 'N/A' }}</div>
                        <div class="col-span-2"><strong class="text-gray-700 dark:text-gray-300">Problem:</strong> {{ $ticketModalProblem['name'] ?? 'N/A' }}</div>
                        <div><strong class="text-gray-700 dark:text-gray-300">Severity:</strong> {{ $ticketModalProblem['severity'] ?? 'N/A' }}</div>
                        <div><strong class="text-gray-700 dark:text-gray-300">Started:</strong> {{ $ticketModalProblem['started_at'] ?? 'N/A' }}</div>
                    </div>
                </div>

                @if(!empty($ticketDefaultWarnings))
                    <div class="bg-warning-50 dark:bg-warning-900/20 p-3 rounded border border-warning-200 dark:border-warning-800 text-warning-700 dark:text-warning-400 text-sm">
                        <strong class="font-medium">Candidate Warnings:</strong>
                        <ul class="list-disc pl-5 mt-1">
                            @foreach($ticketDefaultWarnings as $warn)
                                <li>{{ $warn }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if($ticketValidationStatus === 'success')
                    <div class="bg-success-50 dark:bg-success-900/20 p-3 rounded border border-success-200 dark:border-success-800 text-success-700 dark:text-success-400 text-sm flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-check-circle" class="w-5 h-5" />
                        <span class="font-medium">Ticket data is valid and ready to be created.</span>
                    </div>
                @elseif($ticketValidationStatus === 'error')
                    <div class="bg-danger-50 dark:bg-danger-900/20 p-3 rounded border border-danger-200 dark:border-danger-800 text-danger-700 dark:text-danger-400 text-sm">
                        <strong class="font-medium">Validation Errors:</strong>
                        <ul class="list-disc pl-5 mt-1">
                            @foreach($ticketValidationErrors as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if(!empty($ticketValidationWarnings))
                    <div class="bg-warning-50 dark:bg-warning-900/20 p-3 rounded border border-warning-200 dark:border-warning-800 text-warning-700 dark:text-warning-400 text-sm mt-2">
                        <strong class="font-medium">Validation Warnings:</strong>
                        <ul class="list-disc pl-5 mt-1">
                            @foreach($ticketValidationWarnings as $warn)
                                <li>{{ $warn }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="space-y-4 pt-2">
                    <x-filament::input.wrapper label="Owner" class="flex flex-col">
                        <span class="text-sm font-medium mb-1">Owner <span class="text-danger-600">*</span></span>
                        <x-filament::input.select wire:model="ticketOwnerId">
                            <option value="">Select an owner</option>
                            @foreach($ticketOwnerOptions as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>

                    <x-filament::input.wrapper label="Queue" class="flex flex-col">
                        <span class="text-sm font-medium mb-1">Queue <span class="text-danger-600">*</span></span>
                        <x-filament::input.select wire:model="ticketQueue">
                            <option value="">Select a queue</option>
                            @foreach($ticketQueueOptions as $name => $label)
                                <option value="{{ $name }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>

                    <div class="flex flex-col gap-1">
                        <span class="text-sm font-medium">CustomerUser <span class="text-danger-600">*</span></span>
                        <div class="flex gap-2">
                            <x-filament::input.wrapper class="flex-1">
                                <x-filament::input type="text" wire:model="ticketCustomerUserSearch" placeholder="Search CustomerUser..." wire:keydown.enter="searchTicketCustomerUsers" />
                            </x-filament::input.wrapper>
                            <x-filament::button color="gray" wire:click="searchTicketCustomerUsers">Search</x-filament::button>
                        </div>
                        <x-filament::input.wrapper class="mt-2">
                            <x-filament::input.select wire:model="ticketCustomerUser">
                                <option value="">Select a customer user</option>
                                @foreach($ticketCustomerUserOptions as $login => $label)
                                    <option value="{{ $login }}">{{ $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>

                    <div class="grid grid-cols-2 gap-4 mt-2">
                        <div class="flex flex-col">
                            <span class="text-sm font-medium mb-1 text-gray-500">State</span>
                            <div class="px-3 py-2 bg-gray-100 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 text-sm">new</div>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm font-medium mb-1 text-gray-500">Lock</span>
                            <div class="px-3 py-2 bg-gray-100 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 text-sm">lock</div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <x-slot name="footer">
            <x-filament::button color="gray" wire:click="closeCreateTicketModal">
                Cancel
            </x-filament::button>
            <x-filament::button wire:click="validateTicketData" wire:loading.attr="disabled" wire:target="validateTicketData">
                <span wire:loading.remove wire:target="validateTicketData">Validate ticket data</span>
                <span wire:loading wire:target="validateTicketData">Validating...</span>
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::page>
