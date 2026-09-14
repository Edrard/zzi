<x-filament-panels::page>
    <style>
        .znuny-ticket-body-editor [contenteditable="true"] {
            min-height: 320px;
        }
    </style>
    <form wire:submit="createAndRedirect">
        {{ $this->form }}

        <x-filament::actions alignment="end" class="mt-4">
            <x-filament::button
                type="submit"
                color="primary"
                wire:loading.attr="disabled"
                wire:target="createAndStay, createAndRedirect, create"
            >
                {{ __('create_ticket.actions.create') }}
            </x-filament::button>

            <x-filament::button
                type="button"
                color="gray"
                outlined
                wire:click="createAndStay"
                wire:loading.attr="disabled"
                wire:target="createAndStay, createAndRedirect, create"
            >
                {{ __('create_ticket.actions.create_and_stay') }}
            </x-filament::button>
        </x-filament::actions>
    </form>
</x-filament-panels::page>
