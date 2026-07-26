<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            {{ __('scheduled_znuny_task_runs.review.sections.task') }}
        </x-slot>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.task_id') }}</span>
                <p class="mt-1">{{ $record->task->id ?? '-' }}</p>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.task_name') }}</span>
                <p class="mt-1">{{ $record->task->name ?? '-' }}</p>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.task_enabled') }}</span>
                <p class="mt-1">{{ ($record->task->enabled ?? false) ? __('scheduled_znuny_task_runs.review.fields.yes') : __('scheduled_znuny_task_runs.review.fields.no') }}</p>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">
            {{ __('scheduled_znuny_task_runs.review.sections.run') }}
        </x-slot>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.run_id') }}</span>
                <p class="mt-1">{{ $record->id }}</p>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.run_type') }}</span>
                <p class="mt-1">{{ $record->run_type }}</p>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.run_status') }}</span>
                <p class="mt-1">{{ $record->status }}</p>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.scheduled_time') }}</span>
                <p class="mt-1">{{ $record->scheduled_for?->toDateTimeString() ?? '-' }}</p>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.start_time') }}</span>
                <p class="mt-1">{{ $record->started_at?->toDateTimeString() ?? '-' }}</p>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.finish_time') }}</span>
                <p class="mt-1">{{ $record->finished_at?->toDateTimeString() ?? '-' }}</p>
            </div>
        </div>
    </x-filament::section>

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
            @if($record->latestZnunyTicketCreationAttempt)
                <div class="col-span-full">
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.subject_original') }}</span>
                    <p class="mt-1">{{ $record->latestZnunyTicketCreationAttempt->subject_original ?? '-' }}</p>
                </div>
                <div class="col-span-full">
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.subject_sent') }}</span>
                    <p class="mt-1">{{ $record->latestZnunyTicketCreationAttempt->subject_sent ?? '-' }}</p>
                </div>
                <div>
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.check_count') }}</span>
                    <p class="mt-1">{{ $record->latestZnunyTicketCreationAttempt->check_attempts ?? '-' }}</p>
                </div>
                <div>
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.started_time') }}</span>
                    <p class="mt-1">{{ $record->latestZnunyTicketCreationAttempt->started_at?->toDateTimeString() ?? '-' }}</p>
                </div>
                <div>
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('scheduled_znuny_task_runs.review.fields.last_checked_time') }}</span>
                    <p class="mt-1">{{ $record->latestZnunyTicketCreationAttempt->last_checked_at?->toDateTimeString() ?? '-' }}</p>
                </div>
            @endif
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
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-500 dark:text-gray-400">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-700 dark:bg-gray-800 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-6 py-3">{{ __('scheduled_znuny_task_runs.review.fields.ticket_id') }}</th>
                            <th scope="col" class="px-6 py-3">{{ __('scheduled_znuny_task_runs.review.fields.ticket_number') }}</th>
                            <th scope="col" class="px-6 py-3">{{ __('scheduled_znuny_task_runs.review.fields.ticket_title') }}</th>
                            <th scope="col" class="px-6 py-3">{{ __('scheduled_znuny_task_runs.review.fields.ticket_state') }}</th>
                            <th scope="col" class="px-6 py-3">{{ __('scheduled_znuny_task_runs.review.fields.ticket_state_type') }}</th>
                            <th scope="col" class="px-6 py-3">{{ __('scheduled_znuny_task_runs.review.fields.ticket_queue') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($lookupMatches as $match)
                            <tr class="border-b bg-white dark:border-gray-700 dark:bg-gray-900">
                                <td class="px-6 py-4">{{ $match['TicketID'] ?? '-' }}</td>
                                <td class="px-6 py-4">{{ $match['TicketNumber'] ?? '-' }}</td>
                                <td class="px-6 py-4">{{ $match['Title'] ?? '-' }}</td>
                                <td class="px-6 py-4">{{ $match['State'] ?? '-' }}</td>
                                <td class="px-6 py-4">{{ $match['StateType'] ?? '-' }}</td>
                                <td class="px-6 py-4">{{ $match['Queue'] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('scheduled_znuny_task_runs.review.empty.matches') }}
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
