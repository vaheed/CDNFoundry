@if ($hasMore || $expanded)
    <div class="mt-3 flex justify-end gap-2">
        @if ($hasMore)
            <x-filament::button size="sm" color="gray" icon="phosphor-caret-down" wire:click="{{ $more }}" data-testid="{{ $testid }}">Show more</x-filament::button>
        @endif
        @if ($expanded)
            <x-filament::button size="sm" color="gray" icon="phosphor-caret-up" wire:click="{{ $fewer }}">Show fewer</x-filament::button>
        @endif
    </div>
@endif
