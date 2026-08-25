<x-filament-widgets::widget>
    <x-filament::card wire:poll.10s class="ring-1 ring-gray-950/5 dark:ring-white/10 dark:bg-gray-900 shadow-sm">
        <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <!-- Left Side: Status and Times -->
            <div class="flex flex-col gap-2">
                <div class="flex items-center text-sm font-semibold">
                    <span class="text-gray-700 dark:text-gray-200 mr-3">{{ __('scheduled_znuny_tasks.scheduler.heading') }}</span>
                    <span @class([
                        'px-2 py-0.5 rounded text-xs font-medium mr-3',
                        'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400' => $schedulerStatusRaw === 'enabled',
                        'bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400' => $schedulerStatusRaw === 'paused',
                        'bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400' => $schedulerStatusRaw === 'disabled',
                    ])>
                        {{ $schedulerStatus }}
                    </span>
                    @if($pausedUntil)
                        <span class="text-xs text-warning-600 dark:text-warning-400 ml-2">{{ __('scheduled_znuny_tasks.scheduler.paused_until') }} {{ app(\App\Services\Support\DateTimeDisplayService::class)->diffForHumans($pausedUntil) }} @if($pauseReason) ({{ $pauseReason }}) @endif</span>
                    @endif
                    @if($disabledReason && $schedulerStatusRaw === 'disabled')
                        <span class="text-xs text-danger-600 dark:text-danger-400 ml-2">{{ __('scheduled_znuny_tasks.scheduler.disabled_reason') }} {{ $disabledReason }}</span>
                    @endif
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400 flex flex-wrap items-center mt-1">
                    <span class="mr-4">{{ __('scheduled_znuny_tasks.scheduler.last_processed') }} <span class="font-medium text-gray-700 dark:text-gray-300">{{ $lastProcessed ? app(\App\Services\Support\DateTimeDisplayService::class)->formatLocalizedDateTime($lastProcessed) . ' (' . app(\App\Services\Support\DateTimeDisplayService::class)->diffForHumans($lastProcessed) . ')' : __('scheduled_znuny_tasks.scheduler.never') }}</span></span>
                    <span>{{ __('scheduled_znuny_tasks.scheduler.next_due') }} <span class="font-medium text-gray-700 dark:text-gray-300">{{ $nextDue ? app(\App\Services\Support\DateTimeDisplayService::class)->formatLocalizedDateTime($nextDue) . ' (' . app(\App\Services\Support\DateTimeDisplayService::class)->diffForHumans($nextDue) . ')' : __('scheduled_znuny_tasks.scheduler.none') }}</span></span>
                </div>
            </div>

            <!-- Right Side: Actions -->
            <div class="flex flex-wrap items-center gap-2">
                @if($schedulerStatusRaw !== 'enabled')
                    <x-filament::button wire:click="enableScheduler" color="success" size="sm">{{ __('scheduled_znuny_tasks.scheduler.actions.enable') }}</x-filament::button>
                @endif
                @if($schedulerStatusRaw !== 'disabled')
                    <x-filament::button wire:click="disableScheduler" color="danger" size="sm">{{ __('scheduled_znuny_tasks.scheduler.actions.disable') }}</x-filament::button>
                @endif
                @if($schedulerStatusRaw === 'paused')
                    <x-filament::button wire:click="clearPause" color="warning" size="sm">{{ __('scheduled_znuny_tasks.scheduler.actions.resume') }}</x-filament::button>
                @elseif($schedulerStatusRaw === 'enabled')
                    <x-filament::button wire:click="pauseScheduler" color="warning" size="sm">{{ __('scheduled_znuny_tasks.scheduler.actions.pause') }}</x-filament::button>
                @endif
                <x-filament::button tag="a" href="{{ \App\Filament\Resources\ScheduledZnunyTaskRuns\ScheduledZnunyTaskRunResource::getUrl('index') }}" color="gray" size="sm">{{ __('scheduled_znuny_tasks.scheduler.links.log') }}</x-filament::button>
                <x-filament::button tag="a" href="/admin/settings" color="gray" size="sm">{{ __('scheduled_znuny_tasks.scheduler.links.mail_settings') }}</x-filament::button>
            </div>
        </div>

        <div class="mt-4 pt-4 border-t border-gray-200 dark:border-white/10 flex flex-wrap gap-3 text-sm">
            <div class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 dark:ring-1 dark:ring-white/10 flex items-center gap-2">
                <span class="text-gray-500 dark:text-gray-400">{{ \App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource::getStatusLabel('pending') }}</span>
                <span class="font-bold text-gray-900 dark:text-gray-100">{{ $pendingRuns }}</span>
            </div>
            <div class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 dark:ring-1 dark:ring-white/10 flex items-center gap-2">
                <span class="text-gray-500 dark:text-gray-400">{{ \App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource::getStatusLabel('running') }}</span>
                <span class="font-bold text-sky-600 dark:text-sky-400">{{ $runningRuns }}</span>
            </div>
            <div class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 dark:ring-1 dark:ring-white/10 flex items-center gap-2">
                <span class="text-gray-500 dark:text-gray-400">{{ \App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource::getStatusLabel('success') }}</span>
                <span class="font-bold text-success-600 dark:text-success-400">{{ $successRuns }}</span>
            </div>
            <div class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 dark:ring-1 dark:ring-white/10 flex items-center gap-2">
                <span class="text-gray-500 dark:text-gray-400">{{ \App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource::getStatusLabel('skipped') }}</span>
                <span class="font-bold text-gray-600 dark:text-gray-400">{{ $skippedRuns }}</span>
            </div>
            <div class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 dark:ring-1 dark:ring-white/10 flex items-center gap-2">
                <span class="text-gray-500 dark:text-gray-400">{{ \App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource::getStatusLabel('failed') }}</span>
                <span class="font-bold text-danger-600 dark:text-danger-400">{{ $failedRuns }}</span>
            </div>
            <div class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 dark:ring-1 dark:ring-white/10 flex items-center gap-2">
                <span class="text-gray-500 dark:text-gray-400">{{ \App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource::getStatusLabel('duplicate') }}</span>
                <span class="font-bold text-gray-500 dark:text-gray-400">{{ $duplicateRuns }}</span>
            </div>
            <div class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 dark:ring-1 dark:ring-white/10 flex items-center gap-2">
                <span class="text-gray-500 dark:text-gray-400">{{ \App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource::getStatusLabel('uncertain') }}</span>
                <span class="font-bold text-warning-600 dark:text-warning-400">{{ $uncertainRuns }}</span>
            </div>
        </div>

        @if($lastActiveAlert)
            <div class="mt-4 p-3 rounded-lg bg-amber-50 border border-amber-200 border-l-4 border-l-amber-500 text-amber-900 dark:bg-gray-800/50 dark:border-gray-700/50 dark:border-l-amber-500 dark:text-gray-200 text-sm flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-5 h-5 shrink-0 mt-0.5 text-amber-600 dark:text-amber-500" />
                <div class="flex flex-col">
                    <span class="font-bold leading-tight dark:text-gray-100">{{ $lastActiveAlert->title }}</span>
                    <span class="mt-1 leading-relaxed text-amber-800 dark:text-gray-300">{{ $lastActiveAlert->message }}</span>
                </div>
            </div>
        @endif
    </x-filament::card>
</x-filament-widgets::widget>
