<x-filament-widgets::widget>
    @if (count($problems) > 0)
        <section class="cdn-attention" data-testid="domain-attention" aria-labelledby="domain-attention-heading">
            <h2 id="domain-attention-heading" class="cdn-attention-title">Needs attention <x-ui.status-pill>{{ count($problems) }}</x-ui.status-pill></h2>
            <ul class="cdn-condition-list">
                @foreach ($problems as $problem)
                    <li class="cdn-condition-row" data-tone="warning" data-testid="domain-attention-item">
                        <div class="min-w-0">
                            <div class="cdn-row-title">{{ $problem['text'] }}</div>
                            <div class="cdn-row-meta">{{ $problem['fix'] }}</div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-filament-widgets::widget>
