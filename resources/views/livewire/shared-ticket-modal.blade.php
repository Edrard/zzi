<x-filament::modal id="shared-ticket-details-modal" width="2xl">
    <x-slot name="heading">
        Ticket Details
    </x-slot>

    <div>
        @if($ticket)
            <div style="display: flex; flex-direction: column; gap: 16px;">
                <div>
                    <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Ticket Number</strong>
                    <div style="font-weight: 500;">{{ $ticket['TicketNumber'] ?? '-' }}</div>
                </div>
                <div>
                    <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Title</strong>
                    <div>{{ $ticket['Title'] ?? '-' }}</div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div>
                        <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Queue</strong>
                        <div>{{ $ticket['Queue'] ?? '-' }}</div>
                    </div>
                    <div>
                        <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Owner</strong>
                        <div>{{ $ticket['Owner'] ?? '-' }}</div>
                    </div>
                    <div>
                        <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Customer User</strong>
                        <div>{{ $ticket['CustomerUserID'] ?: '-' }}</div>
                    </div>
                    <div>
                        <strong style="display: block; font-size: 0.875rem; color: #6b7280;">State / Type</strong>
                        <div>
                            <span>{{ $ticket['State'] ?? '-' }}</span>
                            @if(!empty($ticket['StateType']))
                                <span style="color: #6b7280; font-size: 0.875rem;">({{ $ticket['StateType'] }})</span>
                            @endif
                        </div>
                    </div>
                    <div>
                        <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Priority</strong>
                        <div>{{ $ticket['Priority'] ?? '-' }}</div>
                    </div>
                    <div>
                        <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Type</strong>
                        <div>{{ $ticket['Type'] ?: '-' }}</div>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; border-top: 1px solid var(--border-color, #e5e7eb); padding-top: 16px;">
                    <div>
                        <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Created</strong>
                        <div>{{ $ticket['Created'] ?? '-' }}</div>
                    </div>
                    <div>
                        <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Changed</strong>
                        <div>{{ $ticket['Changed'] ?? '-' }}</div>
                    </div>
                    <div>
                        <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Article Count</strong>
                        <div>{{ $ticket['ArticleCount'] ?? '-' }}</div>
                    </div>
                    <div>
                        <strong style="display: block; font-size: 0.875rem; color: #6b7280;">Last Article</strong>
                        <div>{{ $ticket['LastArticleCreated'] ?: '-' }}</div>
                    </div>
                </div>

                @if(!empty($ticket['is_linked_to_zabbix_problem']))
                    @php
                        $isResolved = !empty($ticket['zabbix_problem_is_active']) ? false : true;
                    @endphp
                    <div style="margin-top: 16px; padding: 12px; background: rgba(59, 130, 246, 0.05); border: 1px solid rgba(59, 130, 246, 0.2); border-radius: 8px;">
                        <strong style="display: block; font-size: 0.875rem; color: #0369a1; margin-bottom: 8px;">Linked Zabbix Problem</strong>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 0.875rem;">
                            <div><span style="color: #6b7280;">Host:</span> {{ $ticket['zabbix_host_name'] ?? '-' }}</div>
                            <div><span style="color: #6b7280;">Problem:</span> {{ $ticket['zabbix_problem_name'] ?? '-' }}</div>
                            <div><span style="color: #6b7280;">Event ID:</span> {{ $ticket['zabbix_event_id'] ?? '-' }}</div>
                            <div>
                                <span style="color: #6b7280;">Zabbix State:</span>
                                @if($isResolved)
                                    <span style="color: #16a34a;">Resolved</span>
                                @else
                                    <span style="color: #dc2626;">Active</span>
                                @endif
                            </div>
                            <div><span style="color: #6b7280;">Severity:</span> {{ \App\Services\Zabbix\ZabbixSeverityEnum::tryFrom((int)($ticket['zabbix_severity'] ?? -1))?->getLabel() ?? $ticket['zabbix_severity'] ?? '-' }}</div>
                            <div><span style="color: #6b7280;">Age:</span> {{ !empty($ticket['zabbix_problem_started_at']) ? \Carbon\Carbon::parse($ticket['zabbix_problem_started_at'])->diffForHumans() : '-' }}</div>
                            <div><span style="color: #6b7280;">Lifecycle:</span> {{ $ticket['manual_lifecycle_status'] ?? '-' }}</div>
                        </div>
                    </div>
                @endif
            </div>
        @else
            <div style="padding: 24px; text-align: center; color: #6b7280;">
                Loading ticket details...
            </div>
        @endif
    </div>
</x-filament::modal>
