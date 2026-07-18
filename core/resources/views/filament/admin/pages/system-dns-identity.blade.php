<x-filament-panels::page>
    <form wire:submit="previewChanges" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit">Validate and preview</x-filament::button>
    </form>
    @if ($preview)
        <div class="mt-6 space-y-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <p class="text-sm">Review the normalized high-risk DNS identity payload before applying it.</p>
            <pre class="max-h-80 overflow-auto text-xs">{{ json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            <x-filament::button wire:click="save" color="danger">Confirm and queue update</x-filament::button>
        </div>
    @endif
</x-filament-panels::page>
