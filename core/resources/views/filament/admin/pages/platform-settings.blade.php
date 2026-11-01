<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6" data-testid="settings-form"
          x-data="{ changed: false }"
          x-on:input="changed = true"
          x-on:change="changed = true"
          x-on:click="if ($event.target.closest('[data-testid^=setting-reset]')) changed = true"
          x-on:submit="$nextTick(() => changed = false)">
        {{ $this->form }}
        <div class="flex justify-end">
            <x-filament::button type="submit" x-bind:disabled="! changed" data-testid="settings-save-button">
                Save changes
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
