<x-filament-panels::page>
    @php
        $getEffectiveStatusInfo = function ($run) {
            if ($run->resolution_type === 'manual_closed') {
                return [
                    'label' => __('scheduled_znuny_task_runs.resolution_types.manual_closed'),
                    'class' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
                    'is_badge' => true,
                ];
            }
            if ($run->resolution_type === 'manual_link') {
                return [
                    'label' => __('scheduled_znuny_task_runs.resolution_types.manual_link'),
                    'class' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
                    'is_badge' => true,
                ];
            }
            if ($run->resolution_type === 'retry_created') {
                return [
                    'label' => __('scheduled_znuny_task_runs.resolution_types.retry_created'),
                    'class' => 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300',
                    'is_badge' => true,
                ];
            }
            return [
                'label' => $run->status,
                'class' => 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300',
                'is_badge' => false,
            ];
        };
    @endphp
    <div
        class="fi-grid lg:fi-grid-cols"
        style="
            --cols-default: repeat(1, minmax(0, 1fr));
            --cols-lg: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        "
    >
        <x-filament::section>
            <x-slot name="heading">
                {{ __('scheduled_znuny_task_runs.review.sections.task') }}
            </x-slot>

            <div class="flex flex-col space-y-1">
                <div class="flex items-start justify-between gap-4 py-1.5">
                    <span class="shrink-0 text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.task_id') }}</span>
                    <span class="break-words text-right text-sm font-medium text-gray-900 dark:text-white">{{ $activeRun->task->id ?? '-' }}</span>
                </div>
                <div class="flex items-start justify-between gap-4 py-1.5">
                    <span class="shrink-0 text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.task_name') }}</span>
                    <span class="break-words text-right text-sm font-medium text-gray-900 dark:text-white">{{ $activeRun->task->name ?? '-' }}</span>
                </div>
                <div class="flex items-start justify-between gap-4 py-1.5">
                    <span class="shrink-0 text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.task_enabled') }}</span>
                    <span class="break-words text-right text-sm font-medium text-gray-900 dark:text-white">{{ ($activeRun->task->enabled ?? false) ? __('scheduled_znuny_task_runs.review.fields.yes') : __('scheduled_znuny_task_runs.review.fields.no') }}</span>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                {{ __('scheduled_znuny_task_runs.review.sections.run') }}
            </x-slot>

            <div class="flex flex-col space-y-1">
                <div class="flex items-start justify-between gap-4 py-1.5">
                    <span class="shrink-0 text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.run_id') }}</span>
                    <span class="break-words text-right text-sm font-medium text-gray-900 dark:text-white">{{ $activeRun->id }}</span>
                </div>
                <div class="flex items-start justify-between gap-4 py-1.5">
                    <span class="shrink-0 text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.run_type') }}</span>
                    <span class="break-words text-right text-sm font-medium text-gray-900 dark:text-white">{{ $activeRun->run_type }}</span>
                </div>
                <div class="flex items-start justify-between gap-4 py-1.5">
                    <span class="shrink-0 text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.run_status') }}</span>
                    <div class="break-words text-right text-sm font-medium text-gray-900 dark:text-white">
                        @php $eff = $getEffectiveStatusInfo($activeRun); @endphp
                        @if($eff['is_badge'])
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $eff['class'] }}">
                                {{ $eff['label'] }}
                            </span>
                        @else
                            {{ $eff['label'] }}
                        @endif
                    </div>
                </div>
                <div class="flex items-start justify-between gap-4 py-1.5">
                    <span class="shrink-0 text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.scheduled_time') }}</span>
                    <span class="break-words text-right text-sm font-medium text-gray-900 dark:text-white">{{ $activeRun->scheduled_for?->toDateTimeString() ?? '-' }}</span>
                </div>
                <div class="flex items-start justify-between gap-4 py-1.5">
                    <span class="shrink-0 text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.start_time') }}</span>
                    <span class="break-words text-right text-sm font-medium text-gray-900 dark:text-white">{{ $activeRun->started_at?->toDateTimeString() ?? '-' }}</span>
                </div>
                <div class="flex items-start justify-between gap-4 py-1.5">
                    <span class="shrink-0 text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.finish_time') }}</span>
                    <span class="break-words text-right text-sm font-medium text-gray-900 dark:text-white">{{ $activeRun->finished_at?->toDateTimeString() ?? '-' }}</span>
                </div>
            </div>
        </x-filament::section>
    </div>

    @if($activeRun->latestZnunyTicketCreationAttempt)
    <x-filament::section>
        <x-slot name="heading">
            {{ __('scheduled_znuny_task_runs.review.sections.attempt') }}
        </x-slot>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.attempt_id') }}</span>
                <p class="mt-1">{{ $reviewContext['attempt_id'] ?? '-' }}</p>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.attempt_status') }}</span>
                <p class="mt-1">
                    @php
                        $status = $reviewContext['attempt_status'] ?? '-';
                        echo (is_object($status) && enum_exists(get_class($status))) ? $status->value : (string) $status;
                    @endphp
                </p>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.source_type') }}</span>
                <p class="mt-1">{{ $reviewContext['source_type'] ?? '-' }}</p>
            </div>
            <div class="col-span-full">
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.marker') }}</span>
                <p class="mt-1">{{ $reviewContext['marker'] ?? '-' }}</p>
            </div>
            <div class="col-span-full">
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.subject_original') }}</span>
                <p class="mt-1">{{ $activeRun->latestZnunyTicketCreationAttempt->subject_original ?? '-' }}</p>
            </div>
            <div class="col-span-full">
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.subject_sent') }}</span>
                <p class="mt-1">{{ $activeRun->latestZnunyTicketCreationAttempt->subject_sent ?? '-' }}</p>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.check_count') }}</span>
                <p class="mt-1">{{ $activeRun->latestZnunyTicketCreationAttempt->check_attempts ?? '-' }}</p>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.started_time') }}</span>
                <p class="mt-1">{{ $activeRun->latestZnunyTicketCreationAttempt->started_at?->toDateTimeString() ?? '-' }}</p>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.last_checked_time') }}</span>
                <p class="mt-1">{{ $activeRun->latestZnunyTicketCreationAttempt->last_checked_at?->toDateTimeString() ?? '-' }}</p>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.stored_ticket_id') }}</span>
                <p class="mt-1">{{ $reviewContext['stored_ticket_id'] ?? '-' }}</p>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.stored_ticket_number') }}</span>
                <p class="mt-1">{{ $reviewContext['stored_ticket_number'] ?? '-' }}</p>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">
            {{ __('scheduled_znuny_task_runs.review.sections.lookup') }}
        </x-slot>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.lookup_status') }}</span>
                @php
                    $statusMap = [
                        \App\Enums\ScheduledZnunyTicketMarkerLookupStatus::Found->value => __('scheduled_znuny_task_runs.review.lookup_statuses.found'),
                        \App\Enums\ScheduledZnunyTicketMarkerLookupStatus::Multiple->value => __('scheduled_znuny_task_runs.review.lookup_statuses.multiple'),
                        \App\Enums\ScheduledZnunyTicketMarkerLookupStatus::NotFound->value => __('scheduled_znuny_task_runs.review.lookup_statuses.not_found'),
                        \App\Enums\ScheduledZnunyTicketMarkerLookupStatus::Unavailable->value => __('scheduled_znuny_task_runs.review.lookup_statuses.unavailable'),
                    ];
                    $displayStatus = $statusMap[$lookupStatus ?? ''] ?? __('scheduled_znuny_task_runs.review.empty.not_available');
                @endphp
                <p class="mt-1 font-bold">{{ $displayStatus }}</p>
            </div>
            <div class="col-span-full">
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.lookup_reason') }}</span>
                @php
                    $reasonMap = [
                        \App\Enums\ScheduledZnunyTicketMarkerLookupStatus::Found->value => __('scheduled_znuny_task_runs.review.notifications.found.body'),
                        \App\Enums\ScheduledZnunyTicketMarkerLookupStatus::Multiple->value => __('scheduled_znuny_task_runs.review.notifications.multiple.body'),
                        \App\Enums\ScheduledZnunyTicketMarkerLookupStatus::NotFound->value => __('scheduled_znuny_task_runs.review.notifications.not_found.body'),
                        \App\Enums\ScheduledZnunyTicketMarkerLookupStatus::Unavailable->value => __('scheduled_znuny_task_runs.review.notifications.unavailable.body'),
                    ];
                    $displayReason = $reasonMap[$lookupStatus ?? ''] ?? __('scheduled_znuny_task_runs.review.empty.reason');
                @endphp
                <p class="mt-1">{{ $displayReason }}</p>
            </div>
            @if(isset($reviewContext['refresh_attempted']))
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.refresh_attempted') }}</span>
                <p class="mt-1">{{ $reviewContext['refresh_attempted'] ? __('scheduled_znuny_task_runs.review.fields.yes') : __('scheduled_znuny_task_runs.review.fields.no') }}</p>
            </div>
            @endif
            @if(isset($reviewContext['refresh_succeeded']))
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.refresh_succeeded') }}</span>
                <p class="mt-1">{{ $reviewContext['refresh_succeeded'] ? __('scheduled_znuny_task_runs.review.fields.yes') : __('scheduled_znuny_task_runs.review.fields.no') }}</p>
            </div>
            @endif
            @if(isset($reviewContext['refresh_exit_code']))
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.refresh_exit_code') }}</span>
                <p class="mt-1">{{ $reviewContext['refresh_exit_code'] ?? '-' }}</p>
            </div>
            @endif
            @if($lastRecheckedAt)
            <div class="col-span-full">
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.last_rechecked_at') }}</span>
                <p class="mt-1">{{ $lastRecheckedAt }}</p>
            </div>
            @endif
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">
            {{ __('scheduled_znuny_task_runs.review.sections.matches') }}
        </x-slot>

        @if(count($lookupMatches) > 0)
            <div class="overflow-x-auto rounded-lg ring-1 ring-gray-200 dark:ring-white/10">
                <table class="w-full text-left text-sm text-gray-500 dark:text-gray-400">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-700 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-3 py-2">{{ __('scheduled_znuny_task_runs.review.fields.ticket_id') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('scheduled_znuny_task_runs.review.fields.ticket_number') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('scheduled_znuny_task_runs.review.fields.ticket_title') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('scheduled_znuny_task_runs.review.fields.ticket_state') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('scheduled_znuny_task_runs.review.fields.ticket_state_type') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('scheduled_znuny_task_runs.review.fields.ticket_queue') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach($lookupMatches as $match)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="px-3 py-2">{{ $match['ticket_id'] ?? $match['TicketID'] ?? '-' }}</td>
                                <td class="px-3 py-2">{{ $match['ticket_number'] ?? $match['TicketNumber'] ?? '-' }}</td>
                                <td class="px-3 py-2">{{ $match['title'] ?? $match['Title'] ?? '-' }}</td>
                                <td class="px-3 py-2">{{ $match['state'] ?? $match['State'] ?? '-' }}</td>
                                <td class="px-3 py-2">{{ $match['state_type'] ?? $match['StateType'] ?? '-' }}</td>
                                <td class="px-3 py-2">{{ $match['queue'] ?? $match['Queue'] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.empty.matches') }}</p>
        @endif
    </x-filament::section>
    @else
    <x-filament::section>
        <x-slot name="heading">
            {{ __('scheduled_znuny_task_runs.review.sections.attempt') }}
        </x-slot>
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.empty.no_attempt') }}</p>
    </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">
            {{ __('scheduled_znuny_task_runs.review.sections.retry_chain') }}
        </x-slot>

        @if($isMalformedLineage)
            <div class="rounded-md bg-red-50 p-4 dark:bg-red-900/50">
                <p class="text-sm text-red-700 dark:text-red-400">{{ __('scheduled_znuny_task_runs.review.notifications.malformed_lineage.body') }}</p>
            </div>
        @elseif($retryChain->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">-</p>
        @else
            <div class="overflow-x-auto rounded-lg ring-1 ring-gray-200 dark:ring-white/10">
                <table class="w-full text-left text-sm text-gray-500 dark:text-gray-400">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-700 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-3 py-2">{{ __('scheduled_znuny_task_runs.review.fields.run_id') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('scheduled_znuny_task_runs.review.fields.retry_sequence') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('scheduled_znuny_task_runs.review.fields.run_type') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('scheduled_znuny_task_runs.review.fields.run_status') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('scheduled_znuny_task_runs.review.fields.scheduled_time') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('scheduled_znuny_task_runs.review.fields.start_time') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('scheduled_znuny_task_runs.review.fields.finish_time') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('scheduled_znuny_task_runs.review.fields.created_by') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('scheduled_znuny_task_runs.review.fields.resolved_at') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('scheduled_znuny_task_runs.review.fields.resolution_type') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach($retryChain as $run)
                            @php
                                $isRoot = $run->id === $effectiveRootId;
                                $isLeaf = $run->id === $currentLeafId;
                                $isTargetLeaf = $run->id === $activeRun->id;
                                $isHistorical = ! $isLeaf;
                                $bgClass = 'hover:bg-gray-50 dark:hover:bg-white/5';
                                if ($isTargetLeaf) {
                                    $bgClass = 'bg-blue-50 dark:bg-blue-900/20 hover:bg-blue-100 dark:hover:bg-blue-900/30';
                                }
                                $isResolved = $run->resolved_at !== null;
                            @endphp
                            <tr class="{{ $bgClass }}"
                                data-run-id="{{ $run->id }}"
                                data-retry-sequence="{{ $run->retry_sequence }}"
                                data-current-leaf="{{ $isLeaf ? 'true' : 'false' }}"
                                data-resolved="{{ $isResolved ? 'true' : 'false' }}"
                                data-technical-status="{{ $run->status }}">
                                <td class="px-3 py-2 font-medium text-gray-900 dark:text-white">
                                    {{ $run->id }}
                                    @if($isLeaf)
                                        <span class="ml-2 inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-300">
                                            {{ __('scheduled_znuny_task_runs.review.fields.current_leaf') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">{{ $run->retry_sequence }}</td>
                                <td class="px-3 py-2">{{ $run->run_type }}</td>
                                <td class="px-3 py-2">
                                    @php $eff = $getEffectiveStatusInfo($run); @endphp
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $eff['class'] }}">
                                        {{ $eff['label'] }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">{{ $run->scheduled_for?->toDateTimeString() ?? '-' }}</td>
                                <td class="px-3 py-2">{{ $run->started_at?->toDateTimeString() ?? '-' }}</td>
                                <td class="px-3 py-2">{{ $run->finished_at?->toDateTimeString() ?? '-' }}</td>
                                <td class="px-3 py-2">{{ $run->createdBy?->name ?? '-' }}</td>
                                <td class="px-3 py-2">
                                    @if($isResolved)
                                        {{ $run->resolved_at?->toDateTimeString() ?? '-' }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    @if($isResolved)
                                        @php
                                            $resolutionKey = 'scheduled_znuny_task_runs.resolution_types.' . $run->resolution_type;
                                            $displayResolution = \Illuminate\Support\Facades\Lang::has($resolutionKey)
                                                ? __($resolutionKey)
                                                : ($run->resolution_type ?? '-');
                                        @endphp
                                        <span class="text-xs font-medium text-gray-700 dark:text-gray-300">
                                            {{ $displayResolution }}
                                        </span>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
