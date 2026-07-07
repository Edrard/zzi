<div class="relative" wire:poll.30s="loadAlerts">
    <button wire:click="toggleDropdown" type="button" class="relative flex items-center justify-center w-10 h-10 rounded-full hover:bg-gray-500/10 dark:hover:bg-gray-400/10 focus:outline-none transition">
        <x-filament::icon icon="heroicon-o-bell" class="w-6 h-6 text-gray-500 dark:text-gray-400" />
        
        @if($hasUnread)
            <span class="absolute top-1 right-2 w-2.5 h-2.5 bg-danger-600 rounded-full border-2 border-white dark:border-gray-900"></span>
        @endif
    </button>

    @if($showDropdown)
        <div class="absolute right-0 mt-2 w-80 bg-white dark:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 z-50 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 flex justify-between items-center">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">System Alerts</h3>
            </div>
            
            <div class="max-h-96 overflow-y-auto">
                @forelse($activeAlerts as $alert)
                    <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    @if($alert->severity === 'danger')
                                        <span class="w-2 h-2 rounded-full bg-danger-600"></span>
                                    @elseif($alert->severity === 'warning')
                                        <span class="w-2 h-2 rounded-full bg-warning-600"></span>
                                    @else
                                        <span class="w-2 h-2 rounded-full bg-info-600"></span>
                                    @endif
                                    <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                                        {{ $alert->title }}
                                    </p>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 break-words">
                                    {{ $alert->message }}
                                </p>
                                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                                    {{ $alert->created_at->diffForHumans() }} &middot; {{ ucfirst($alert->source) }}
                                </p>
                            </div>
                            <button wire:click="acknowledge({{ $alert->id }})" type="button" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 flex-shrink-0" title="Acknowledge">
                                <x-filament::icon icon="heroicon-o-check" class="w-5 h-5" />
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                        No active alerts.
                    </div>
                @endforelse
            </div>
        </div>
        
        <div wire:click="toggleDropdown" class="fixed inset-0 z-40" style="display: none;" x-show="true"></div>
    @endif
</div>
