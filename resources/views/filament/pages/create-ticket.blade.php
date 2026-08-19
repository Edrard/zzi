<x-filament-panels::page>
    <style>
        .znuny-ticket-body-editor [contenteditable="true"] {
            min-height: 320px;
        }
    </style>
    <form wire:submit="create">
        {{ $this->form }}

        <x-filament::actions alignment="end" class="mt-4">
            <x-filament::button type="submit">
                {{ __('create_ticket.actions.submit') }}
            </x-filament::button>
        </x-filament::actions>
    </form>
</x-filament-panels::page>
