<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}
        <x-ui.form-actions submit="save" label="Validate and save platform settings" loading-label="Validating and saving…" />
    </form>
</x-filament-panels::page>
