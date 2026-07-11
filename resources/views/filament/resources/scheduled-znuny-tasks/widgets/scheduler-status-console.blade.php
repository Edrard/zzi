<x-filament-widgets::widget>
    <x-filament::card wire:poll.10s class="ring-1 ring-gray-950/5 dark:ring-white/10 dark:bg-gray-900 shadow-sm">
        <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <!-- Left Side: Status and Times -->
            <div class="flex flex-col gap-2">
                <div class="flex items-center text-sm font-semibold">
                    <span class="text-gray-700 dark:text-gray-200 mr-3">Scheduler</span>
                    <span @class([
                        'px-2 py-0.5 rounded text-xs font-medium mr-3',
                        'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400' => $schedulerStatus === 'Enabled',
                        'bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400' => $schedulerStatus === 'Paused',
                        'bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400' => $schedulerStatus === 'Disabled',
                    ])>
                        {{ $schedulerStatus }}
                    </span>
                    @if($pausedUntil)
                        <span class="text-xs text-warning-600 dark:text-warning-400 ml-2">Paused Until: {{ \Carbon\Carbon::parse($pausedUntil)->diffForHumans() }} @if($pauseReason) ({{ $pauseReason }}) @endif</span>
                    @endif
                    @if($disabledReason && $schedulerStatus === 'Disabled')
                        <span class="text-xs text-danger-600 dark:text-danger-400 ml-2">Disabled: {{ $disabledReason }}</span>
                    @endif
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400 flex flex-wrap items-center mt-1">
                    <span class="mr-4">Last processed: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $lastProcessed ? \Carbon\Carbon::parse($lastProcessed)->timezone($lastProcessedTz)->format('Y-m-d H:i:s e') . ' (' . $lastProcessed->diffForHumans() . ')' : 'Never' }}</span></span>
                    <span>Next due: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $nextDue ? \Carbon\Carbon::parse($nextDue)->timezone($nextDueTz)->format('Y-m-d H:i:s e') . ' (' . $nextDue->diffForHumans() . ')' : 'None' }}</span></span>
                </div>
            </div>

            <!-- Right Side: Actions -->
            <div class="flex flex-wrap items-center gap-2">
                @if($schedulerStatus !== 'Enabled')
                    <x-filament::button wire:click="enableScheduler" color="success" size="sm">Enable</x-filament::button>
                @endif
                @if($schedulerStatus !== 'Disabled')
                    <x-filament::button wire:click="disableScheduler" color="danger" size="sm">Disable</x-filament::button>
                @endif
                @if($schedulerStatus === 'Paused')
                    <x-filament::button wire:click="clearPause" color="warning" size="sm">Clear Pause</x-filament::button>
                @elseif($schedulerStatus === 'Enabled')
                    <x-filament::button wire:click="pauseScheduler" color="warning" size="sm">Pause</x-filament::button>
                @endif
                <x-filament::button tag="a" href="{{ \App\Filament\Resources\ScheduledZnunyTaskRuns\ScheduledZnunyTaskRunResource::getUrl('index') }}" color="gray" size="sm">Scheduler Log</x-filament::button>
                <x-filament::button tag="a" href="/admin/settings" color="gray" size="sm">Mail Settings</x-filament::button>
            </div>
        </div>

        <div class="mt-4 pt-4 border-t border-gray-200 dark:border-white/10 flex flex-wrap gap-3 text-sm">
            <div class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 dark:ring-1 dark:ring-white/10 flex items-center gap-2">
                <span class="text-gray-500 dark:text-gray-400">Pending</span>
                <span class="font-bold text-gray-900 dark:text-gray-100">{{ $pendingRuns }}</span>
            </div>
            <div class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 dark:ring-1 dark:ring-white/10 flex items-center gap-2">
                <span class="text-gray-500 dark:text-gray-400">Running</span>
                <span class="font-bold text-sky-600 dark:text-sky-400">{{ $runningRuns }}</span>
            </div>
            <div class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 dark:ring-1 dark:ring-white/10 flex items-center gap-2">
                <span class="text-gray-500 dark:text-gray-400">Success</span>
                <span class="font-bold text-success-600 dark:text-success-400">{{ $successRuns }}</span>
            </div>
            <div class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 dark:ring-1 dark:ring-white/10 flex items-center gap-2">
                <span class="text-gray-500 dark:text-gray-400">Skipped</span>
                <span class="font-bold text-gray-600 dark:text-gray-400">{{ $skippedRuns }}</span>
            </div>
            <div class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 dark:ring-1 dark:ring-white/10 flex items-center gap-2">
                <span class="text-gray-500 dark:text-gray-400">Failed</span>
                <span class="font-bold text-danger-600 dark:text-danger-400">{{ $failedRuns }}</span>
            </div>
            <div class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 dark:ring-1 dark:ring-white/10 flex items-center gap-2">
                <span class="text-gray-500 dark:text-gray-400">Uncertain</span>
                <span class="font-bold text-warning-600 dark:text-warning-400">{{ $uncertainRuns }}</span>
            </div>
        </div>

        @if($lastActiveAlert)
            <div class="mt-4 p-2 rounded bg-warning-50 border border-warning-200 text-warning-800 dark:bg-warning-900/30 dark:border-warning-800 dark:text-warning-300 text-xs flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-4 h-4 shrink-0" />
                <span class="font-bold">{{ $lastActiveAlert->title }}:</span>
                <span class="truncate">{{ $lastActiveAlert->message }}</span>
            </div>
        @endif
    </x-filament::card>
</x-filament-widgets::widget>
