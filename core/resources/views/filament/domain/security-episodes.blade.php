@php
    $names = ['waf' => 'Managed WAF'];
    $rule = fn (string $ref): string => $names[$ref] ?? $ref;
    $time = fn (string $iso): string => \Carbon\CarbonImmutable::parse($iso)->utc()->format('M j, H:i');
@endphp
@if ($state === 'unavailable')
    <p class="cdn-dl-row">The telemetry store did not answer. Serving is not affected. Try again in a minute.</p>
@elseif ($episodes === [])
    <p class="cdn-dl-row">No attack in the last 24 hours. An episode appears once at least {{ $minimum }} requests were blocked and ends after {{ $quiet }} quiet minutes.</p>
@else
    <div class="overflow-x-auto" role="region" aria-label="Attack episodes" tabindex="0">
        <table class="w-full text-sm" data-testid="attack-episodes">
            <thead>
                <tr class="text-left">
                    <th scope="col" class="py-2 pe-3 font-medium">Time (UTC)</th>
                    <th scope="col" class="py-2 pe-3 font-medium text-end">Blocked</th>
                    <th scope="col" class="py-2 pe-3 font-medium text-end">Peak per minute</th>
                    <th scope="col" class="py-2 pe-3 font-medium">Top rules</th>
                    <th scope="col" class="py-2 pe-3 font-medium">Top paths</th>
                    <th scope="col" class="py-2 font-medium">Top client keys</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($episodes as $episode)
                    <tr class="align-top border-t">
                        <td class="py-2 pe-3">{{ $time($episode['start']) }} to {{ $time($episode['end']) }}<br><span class="text-xs">{{ $episode['duration_minutes'] }} min</span></td>
                        <td class="py-2 pe-3 text-end tabular-nums">{{ number_format($episode['blocked']) }}</td>
                        <td class="py-2 pe-3 text-end tabular-nums">{{ number_format($episode['peak_per_minute']) }}</td>
                        <td class="py-2 pe-3">{{ collect($episode['top_rules'])->map(fn ($row) => $rule($row['value']).' ('.number_format($row['blocked']).')')->implode(', ') ?: 'None' }}</td>
                        <td class="py-2 pe-3 break-all">{{ collect($episode['top_paths'])->map(fn ($row) => \Illuminate\Support\Str::limit($row['value'], 40).' ('.number_format($row['blocked']).')')->implode(', ') ?: 'None' }}</td>
                        <td class="py-2 font-mono text-xs">{{ collect($episode['top_client_keys'])->map(fn ($row) => $row['value'].' ('.number_format($row['blocked']).')')->implode(', ') ?: 'None' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
