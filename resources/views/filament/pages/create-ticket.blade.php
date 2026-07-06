<x-filament-panels::page>
    <form wire:submit="create">
        {{ $this->form }}

        <x-filament::actions alignment="end" class="mt-4">
            <x-filament::button type="submit">
                Create Ticket
            </x-filament::button>
        </x-filament::actions>
    </form>
</x-filament-panels::page>
