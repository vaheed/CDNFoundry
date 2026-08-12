<x-filament-panels::page>
    <form wire:submit="previewChanges" class="space-y-6">
        {{ $this->form }}
        <x-ui.form-actions submit="previewChanges" label="Validate and preview" loading-label="Validating…" />
    </form>
</x-filament-panels::page>
