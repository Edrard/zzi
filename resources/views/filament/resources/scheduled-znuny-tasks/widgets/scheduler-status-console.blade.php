<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between mb-4">
            <div class="text-lg font-bold">
                Scheduler Status: 
                <span @class([
                    'text-success-600' => $schedulerStatus === 'Enabled',
                    'text-warning-600' => $schedulerStatus === 'Paused',
                    'text-danger-600' => $schedulerStatus === 'Disabled',
                ])>
                    {{ $schedulerStatus }}
                </span>
            </div>
            <div class="flex gap-2">
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

        <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-4">
            <div class="p-4 rounded-lg bg-gray-50 dark:bg-gray-800">
                <div class="text-sm text-gray-500">Pending Runs</div>
                <div class="text-2xl font-bold">{{ $pendingRuns }}</div>
            </div>
            <div class="p-4 rounded-lg bg-gray-50 dark:bg-gray-800">
                <div class="text-sm text-gray-500">Running Runs</div>
                <div class="text-2xl font-bold">{{ $runningRuns }}</div>
            </div>
            <div class="p-4 rounded-lg bg-gray-50 dark:bg-gray-800">
                <div class="text-sm text-gray-500">Success Runs</div>
                <div class="text-2xl font-bold text-success-600">{{ $successRuns }}</div>
            </div>
            <div class="p-4 rounded-lg bg-gray-50 dark:bg-gray-800">
                <div class="text-sm text-gray-500">Skipped Runs</div>
                <div class="text-2xl font-bold text-gray-600">{{ $skippedRuns }}</div>
            </div>
            <div class="p-4 rounded-lg bg-gray-50 dark:bg-gray-800">
                <div class="text-sm text-gray-500">Failed Runs</div>
                <div class="text-2xl font-bold text-danger-600">{{ $failedRuns }}</div>
            </div>
            <div class="p-4 rounded-lg bg-gray-50 dark:bg-gray-800">
                <div class="text-sm text-gray-500">Uncertain Runs</div>
                <div class="text-2xl font-bold text-warning-600">{{ $uncertainRuns }}</div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div>
                <strong>Last Processed:</strong> {{ $lastProcessed ? $lastProcessed->diffForHumans() : 'Never' }}
            </div>
            <div>
                <strong>Next Due:</strong> {{ $nextDue ? $nextDue->diffForHumans() : 'None' }}
            </div>
            @if($pausedUntil)
                <div class="text-warning-600">
                    <strong>Paused Until:</strong> {{ \Carbon\Carbon::parse($pausedUntil)->diffForHumans() }} 
                    @if($pauseReason) ({{ $pauseReason }}) @endif
                </div>
            @endif
            @if($disabledReason && $schedulerStatus === 'Disabled')
                <div class="text-danger-600">
                    <strong>Disabled Reason:</strong> {{ $disabledReason }}
                </div>
            @endif
        </div>

        @if($lastActiveAlert)
            <div class="mt-4 p-4 rounded-lg bg-warning-50 border border-warning-200 text-warning-800 dark:bg-warning-900/30 dark:border-warning-800 dark:text-warning-300">
                <div class="font-bold flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-5 h-5" />
                    Latest Active Alert: {{ $lastActiveAlert->title }}
                </div>
                <div class="mt-1 text-sm">{{ $lastActiveAlert->message }}</div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
