<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-4">
        @foreach ($this->summary as $label => $value)
            <x-filament::section><div class="text-sm text-gray-500">{{ str($label)->replace('_', ' ')->title() }}</div><div class="text-3xl font-semibold">{{ $value }}</div></x-filament::section>
        @endforeach
    </div>
    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section heading="Queue lanes">
            <dl class="space-y-2">@foreach ($this->queueState as $queue => $depth)<div class="flex justify-between"><dt>{{ $queue }}</dt><dd>{{ $depth }}</dd></div>@endforeach</dl>
        </x-filament::section>
        <x-filament::section heading="Recent audit activity">
            <div class="space-y-3">@forelse ($this->recentAudits as $entry)<div><strong>{{ $entry->action }}</strong><div class="text-sm text-gray-500">{{ $entry->actor?->email ?? 'system' }} · {{ $entry->created_at?->diffForHumans() }}</div></div>@empty<p>No audit activity yet.</p>@endforelse</div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
