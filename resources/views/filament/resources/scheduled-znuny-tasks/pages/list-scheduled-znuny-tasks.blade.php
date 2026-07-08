<style>
@media (max-width: 900px) {
    .scheduled-task-filter-row {
        grid-template-columns: 1fr !important;
    }
}
</style>

<x-filament-panels::page>
    <div class="p-4 bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 rounded-xl mb-4">
        <div
            class="scheduled-task-filter-row"
            style="display:grid;grid-template-columns:minmax(260px,1.2fr) minmax(190px,1fr) minmax(190px,1fr) minmax(160px,.7fr);gap:12px;align-items:end;"
        >
            <x-filament::input.wrapper icon="heroicon-m-magnifying-glass">
                <x-filament::input type="search" wire:model.live.debounce.500ms="taskSearch" placeholder="Search tasks..." />
            </x-filament::input.wrapper>

            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="queueFilter">
                    <option value="">All Queues</option>
                    @foreach($this->getQueueOptions() as $queue)
                        <option value="{{ $queue }}">{{ $queue }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>

            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="ownerFilter">
                    <option value="">All Owners</option>
                    @foreach($this->getOwnerOptions() as $owner)
                        <option value="{{ $owner }}">{{ $owner }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>

            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="activeFilter">
                    <option value="all">All Status</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    </div>
    
    {{ $this->table }}
</x-filament-panels::page>
