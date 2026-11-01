<x-filament-widgets::widget>
    @if (count($problems) > 0)
        <section class="cdn-attention" data-testid="domain-attention" aria-labelledby="domain-attention-heading">
            <h2 id="domain-attention-heading" class="cdn-attention-title">Needs attention</h2>
            <ul class="p-4 pt-2">
                @foreach ($problems as $problem)
                    <li class="cdn-row-title" data-testid="domain-attention-item">{{ $problem['text'] }}<span class="cdn-row-meta block">{{ $problem['fix'] }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif
</x-filament-widgets::widget>
