@php
    $stages = ['none' => 'No mitigation', 'managed_waf' => 'Managed WAF', 'domain_rule' => 'Domain rule', 'platform_rule' => 'Platform rule', 'rate_limit' => 'Rate limit', 'challenge' => 'Challenge', 'sensitive_data' => 'Sensitive data', 'api_protection' => 'API protection', 'platform_limit' => 'Platform limit'];
    $names = ['client_key' => 'Client key', 'snapshot_sequence' => 'Snapshot', 'reputation_band' => 'Reputation band', 'bot_components' => 'Bot signals', 'platform_datasets' => 'Platform datasets'];
    $join = fn (array $items): string => $items === [] ? 'None' : implode(', ', $items);
@endphp
<dl>
    <div class="cdn-dl-row"><dt>Stage</dt><dd>{{ $stages[$trace['stage']] ?? $trace['stage'] }}</dd></div>
    <div class="cdn-dl-row"><dt>Reason</dt><dd>{{ $trace['acted'] ? str_replace('_', ' ', $trace['acted']) : 'None' }}</dd></div>
    <div class="cdn-dl-row"><dt>Rules that acted</dt><dd>{{ $join($trace['rules']) }}</dd></div>
    <div class="cdn-dl-row"><dt>Shadow rules that matched</dt><dd>{{ $join($trace['shadow_rules']) }}</dd></div>
    <div class="cdn-dl-row"><dt>Managed rule IDs</dt><dd>{{ $join($trace['managed_rule_ids']) }}</dd></div>
    <div class="cdn-dl-row"><dt>Scores</dt><dd>{{ $trace['scores'] === [] ? 'Not scored' : collect($trace['scores'])->map(fn ($score, $name) => $name.' '.$score)->implode(', ') }}</dd></div>
    <div class="cdn-dl-row"><dt>Client key</dt><dd>{{ $trace['client_key'] ?? 'Not recorded' }}</dd></div>
    <div class="cdn-dl-row"><dt>Snapshot sequence</dt><dd>{{ $trace['snapshot_sequence'] ?? 'Not recorded' }}</dd></div>
    <div class="cdn-dl-row"><dt>Not recorded</dt><dd>{{ collect($trace['unavailable'])->reject(fn ($key) => in_array($key, ['client_key', 'snapshot_sequence'], true))->map(fn ($key) => $names[$key] ?? $key)->implode(', ') }}</dd></div>
</dl>
