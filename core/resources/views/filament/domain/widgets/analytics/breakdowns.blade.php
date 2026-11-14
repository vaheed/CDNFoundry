<x-filament-widgets::widget>
    <x-filament::section :heading="$heading ?? 'Breakdown'" :data-testid="$sectionId ?? 'analytics-breakdowns'">
        <x-filament::tabs :label="$heading ?? 'Breakdown'" class="cdn-widget-tabs">
            @foreach ($tabs as $key => $label)
                <x-filament::tabs.item :active="$active === $key" wire:click="selectTab('{{ $key }}')" data-testid="{{ $prefix ?? 'analytics-breakdown' }}-{{ $key }}">{{ $label }}</x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
        <div class="mt-3" wire:loading.class="opacity-60" wire:target="selectTab,showMore,showFewer">
            @if ($note)
                <p class="cdn-row-meta mb-2">{{ $note }}</p>
            @endif
            @if ($state === 'unavailable')
                <x-ui.widget-state state="unavailable" description="This breakdown could not be loaded. Serving is not affected." />
            @elseif ($state === 'no_data')
                <x-ui.widget-state state="no_data" description="Nothing was recorded for these domains in this range." />
            @else
                <x-ui.data-table :caption="$tabs[$active]" fixed>
                    <x-slot:header><tr>@foreach ($columns as $key => $label)<th @class(['text-left' => in_array($key, ['label', 'secondary'], true), 'text-right' => ! in_array($key, ['label', 'secondary'], true), 'hidden md:table-cell' => $key === 'secondary'])>{{ $label }}</th>@endforeach</tr></x-slot:header>
                    @foreach ($rows as $row)
                        <tr>@foreach ($columns as $key => $label)
                            @if ($key === 'label')
                                <td class="px-3 py-2 font-medium" @if (mb_strlen($row[$key]) > 40) title="{{ $row[$key] }}" @endif><span @class(['cdn-code' => in_array($active, ['paths', 'agents'], true)])>{{ $row[$key] }}</span></td>
                            @elseif ($key === 'secondary')
                                <td class="hidden px-3 py-2 md:table-cell">{{ $row[$key] }}</td>
                            @else
                                <td class="px-3 py-2 text-right tabular-nums">{{ $row[$key] }}</td>
                            @endif
                        @endforeach</tr>
                    @endforeach
                </x-ui.data-table>
                @include('filament.domain.widgets.analytics.more', ['hasMore' => $hasMore, 'expanded' => $this->limit > 10, 'more' => 'showMore', 'fewer' => 'showFewer', 'testid' => ($prefix ?? 'analytics-breakdown').'-more'])
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
