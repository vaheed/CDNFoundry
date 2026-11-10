<x-filament-widgets::widget>
    <x-filament::section heading="Platform" description="Counted from the control database, not from telemetry." icon="phosphor-hard-drives">
        <div wire:poll.30s data-testid="platform-inventory">
            <x-ui.kpi-strip :items="$items" />
            <dl class="cdn-fact-grid mt-4">
                @foreach ($facts as $label => $value)
                    <div>
                        <dt>{{ $label }}</dt>
                        <dd class="truncate" @if (strlen((string) $value) > 40) title="{{ $value }}" @endif>{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
