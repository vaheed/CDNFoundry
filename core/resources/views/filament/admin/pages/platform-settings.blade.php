<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit">Validate and save platform settings</x-filament::button>
    </form>
</x-filament-panels::page>
