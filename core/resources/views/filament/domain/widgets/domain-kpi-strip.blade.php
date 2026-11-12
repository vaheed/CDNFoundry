<x-filament-widgets::widget>
    <div @if ($pending) wire:poll.5s="pollDomain" @endif data-testid="domain-kpi-strip" data-pending="{{ $pending ? 'true' : 'false' }}">
        <x-ui.kpi-strip :items="$items" />
    </div>
</x-filament-widgets::widget>
