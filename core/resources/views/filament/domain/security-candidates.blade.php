@php
    $names = ['waf' => 'Managed WAF'];
    $time = fn (string $value): string => \Carbon\CarbonImmutable::parse($value, 'UTC')->format('M j, H:i');
@endphp
@if ($state === 'unavailable')
    <p class="cdn-dl-row">The telemetry store did not answer. Serving is not affected. Try again in a minute.</p>
@elseif ($candidates === [])
    <p class="cdn-dl-row">No client was blocked and then solved a challenge in the last 24 hours. Challenges are logged only while a challenge rule or an incident challenge is active.</p>
@else
    <div class="overflow-x-auto" role="region" aria-label="Possible false positives" tabindex="0">
        <table class="w-full text-sm" data-testid="false-positive-candidates">
            <thead>
                <tr class="text-left">
                    <th scope="col" class="py-2 pe-3 font-medium">Client key</th>
                    <th scope="col" class="py-2 pe-3 font-medium text-end">Blocked</th>
                    <th scope="col" class="py-2 pe-3 font-medium text-end">Challenges solved</th>
                    <th scope="col" class="py-2 pe-3 font-medium">Blocked most by</th>
                    <th scope="col" class="py-2 font-medium">First block to last solve (UTC)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($candidates as $candidate)
                    <tr class="align-top border-t">
                        <td class="py-2 pe-3 font-mono text-xs">{{ $candidate['client_key'] }}</td>
                        <td class="py-2 pe-3 text-end tabular-nums">{{ number_format($candidate['blocked']) }}</td>
                        <td class="py-2 pe-3 text-end tabular-nums">{{ number_format($candidate['solved']) }}</td>
                        <td class="py-2 pe-3 break-all">{{ $names[$candidate['top_rule']] ?? $candidate['top_rule'] }}</td>
                        <td class="py-2">{{ $time($candidate['first_blocked']) }} to {{ $time($candidate['last_solved']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
