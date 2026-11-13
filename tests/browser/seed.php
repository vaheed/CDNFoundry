<?php

use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\DnsDeployment;
use App\Models\Domain;
use App\Models\DomainEdgeCell;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgePool;
use App\Models\EdgeProbeResult;
use App\Models\EdgeRevision;
use App\Models\LogPushDestination;
use App\Models\NotificationChannel;
use App\Models\Operation;
use App\Models\SystemSetting;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\DnsRecordData;
use App\Support\SecurityRuleData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Seeds the disposable browser database only. Credentials come from the environment, generated per run.
// The data is deliberately awkward (long names, IPv6, many rows, failures) so layout problems show up in tests.
$admin = User::query()->create([
    'name' => 'Browser Admin', 'email' => 'admin@browser.test', 'type' => UserType::Admin,
    'password' => Hash::make(getenv('CDNF_BROWSER_ADMIN_PASSWORD')), 'email_verified_at' => now(),
]);
$user = User::query()->create([
    'name' => 'Browser User', 'email' => 'user@browser.test', 'type' => UserType::User,
    'password' => Hash::make(getenv('CDNF_BROWSER_USER_PASSWORD')), 'email_verified_at' => now(),
]);
foreach (range(1, 18) as $n) {
    User::query()->create([
        'name' => "Customer Contact Number {$n}", 'email' => "customer-{$n}-with-a-long-address@subdomain.example-company.test",
        'type' => UserType::User, 'password' => Hash::make(bin2hex(random_bytes(12))), 'email_verified_at' => now(),
        'disabled_at' => $n % 7 === 0 ? now() : null,
    ]);
}

$names = ['alpha-browser.example', 'beta-browser.example', 'gamma-browser.example',
    'a-remarkably-long-customer-domain-name-for-layout-testing.subdomain.example-company.co.uk',
    'xn--bcher-kva.example', 'shop.example', 'blog.example', 'api.example', 'status.example', 'docs.example'];
foreach (range(1, 30) as $n) {
    $names[] = "tenant-{$n}.example.net";
}
$domains = [];
foreach ($names as $name) {
    $domains[$name] = Domain::createPendingFor($user, $name, '127.0.0.1');
    DB::table('domains')->where('id', $domains[$name]->id)->update(['nameservers_verified_at' => now()]);
}
Domain::createPendingFor($admin, 'private-browser.example', '127.0.0.1');

$clusters = [
    DnsCluster::query()->create(['name' => 'dns-primary', 'location' => 'Tehran', 'enabled' => true, 'api_url' => 'http://dns-primary.test', 'api_key' => 'secret-1', 'nameservers' => ['ns1.cdnf.test', 'ns2.cdnf.test'], 'last_health_status' => 'healthy']),
    DnsCluster::query()->create(['name' => 'dns-secondary-with-a-long-name', 'location' => 'Frankfurt', 'enabled' => false, 'api_url' => 'http://dns-secondary.test', 'api_key' => 'secret-2', 'nameservers' => ['ns3.cdnf.test', 'ns4.cdnf.test'], 'last_health_status' => 'unhealthy']),
];
$shared = EdgePool::query()->where('kind', 'shared')->orderBy('id')->first()
    ?? EdgePool::query()->create(['name' => 'shared-default', 'kind' => 'shared', 'enabled' => true]);
$reserved = EdgePool::query()->create(['name' => 'quarantine-pool-for-suspicious-traffic', 'kind' => 'reserved', 'enabled' => true]);
$edges = [];
foreach (['edge-tehran-a', 'edge-tehran-b', 'edge-frankfurt-a', 'edge-with-a-very-long-point-of-presence-name'] as $i => $edgeName) {
    $edge = Edge::query()->create([
        'name' => $edgeName, 'country_code' => $i < 2 ? 'IR' : 'DE', 'continent_code' => $i < 2 ? 'AS' : 'EU',
        'registered_at' => now(), 'last_heartbeat_at' => $i === 3 ? now()->subDays(2) : now(),
    ]);
    $edges[] = [$edge, $edge->cells()->create(['slot' => 1, 'edge_pool_id' => $shared->id, 'status' => 'assigned'])];
}
// The fourth edge has not reported for two days but the other PoPs still reach it: stale control link, serving edge.
// The third edge is reached by nobody: fresh heartbeat, not serving.
// Probe results count only while recent, and the suite runs for many minutes, so the fixture is dated ahead.
foreach ([0, 1] as $observer) {
    EdgeProbeResult::query()->create(['observer_edge_id' => $edges[$observer][0]->id, 'target_edge_id' => $edges[3][0]->id, 'address' => '192.0.2.44', 'ok' => true, 'checked_at' => now()->addYear()]);
    EdgeProbeResult::query()->create(['observer_edge_id' => $edges[$observer][0]->id, 'target_edge_id' => $edges[2][0]->id, 'address' => '192.0.2.43', 'ok' => false, 'checked_at' => now()->addYear()]);
}

$showcase = $domains['alpha-browser.example'];
DB::table('domains')->whereIn('id', collect($domains)->pluck('id'))->update(['lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
DB::table('domains')->where('id', $domains['beta-browser.example']->id)->update(['nameservers_verified_at' => null, 'lifecycle_state' => 'pending_verification']);
$showcase->refresh();
$records = [
    ['A', '@', '192.0.2.10'], ['A', 'www', '192.0.2.10'], ['AAAA', '@', '2001:db8:85a3::8a2e:370:7334'],
    ['AAAA', 'www', '2001:db8:85a3::8a2e:370:7334'], ['CNAME', 'blog', 'hosting.provider-with-a-long-name.example.net.'],
    ['MX', '@', 'mail.provider.example.', 10], ['TXT', '@', 'v=spf1 include:_spf.google.com include:sendgrid.net include:mailgun.org ~all'],
    ['TXT', 'selector1-domainkey', 'v=DKIM1; k=rsa; p='.str_repeat('MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA', 6)],
    ['TXT', 'dmarc', 'v=DMARC1; p=quarantine; rua=mailto:dmarc-reports@alpha-browser.example'],
];
foreach (range(1, 14) as $n) {
    $records[] = ['A', "service-{$n}", '198.51.100.'.$n];
}
foreach ($records as $record) {
    [$type, $name, $content] = $record;
    $priority = $record[3] ?? null;
    $showcase->dnsRecords()->create(DnsRecordData::validate(compact('type', 'name', 'content') + ['ttl' => 300] + ($priority === null ? [] : ['priority' => $priority]), $showcase->name));
}
$showcase->dnsRecords()->whereIn('name', ['@', 'www'])->whereIn('type', ['A', 'AAAA'])->update(['mode' => 'proxied']);

$showcase->dnsRecords()->create(DnsRecordData::validate([
    'type' => 'A', 'name' => 'geo-routed', 'ttl' => 60, 'mode' => 'geo_dns',
    'geo' => ['default' => ['203.0.113.10'], 'countries' => ['IR' => ['203.0.113.30'], 'GB' => ['203.0.113.40']], 'continents' => ['EU' => ['203.0.113.20']]],
], $showcase->name));

DomainEdgePlacement::query()->create(['domain_id' => $showcase->id, 'active_pool_id' => $shared->id, 'desired_revision' => 100, 'state' => 'active']);
foreach (array_slice($edges, 0, 3) as $i => [$edge, $cell]) {
    DomainEdgeCell::query()->create(['domain_id' => $showcase->id, 'edge_id' => $edge->id, 'replica' => 1, 'active_cell_id' => $cell->id, 'desired_revision' => 100, 'state' => 'active']);
}
foreach (range(91, 100) as $revision) {
    EdgeRevision::query()->create(['domain_id' => $showcase->id, 'revision' => $revision, 'status' => 'validated', 'snapshot' => ['revision' => $revision], 'checksum' => hash('sha256', (string) $revision)]);
}
DB::table('domains')->where('id', $showcase->id)->update(['revision' => 100, 'active_edge_revision' => 100]);

$certificate = $showcase->tlsCertificates()->create([
    'kind' => 'managed', 'status' => 'active', 'certificate_pem' => 'pem', 'chain_pem' => '', 'private_key_ciphertext' => 'key',
    'names' => ['alpha-browser.example', '*.alpha-browser.example', 'www.alpha-browser.example', 'mail.alpha-browser.example',
        'a-very-long-subject-alternative-name.internal.alpha-browser.example', 'api.alpha-browser.example'],
    'fingerprint_sha256' => hash('sha256', 'cert'), 'not_before' => now()->subDays(30), 'expires_at' => now()->addDays(60), 'activated_at' => now(),
]);
DB::table('domains')->where('id', $showcase->id)->update(['active_tls_certificate_id' => $certificate->id]);
foreach ($clusters as $i => $cluster) {
    DnsDeployment::query()->create([
        'domain_id' => $showcase->id, 'dns_cluster_id' => $cluster->id, 'desired_revision' => 100, 'deployed_revision' => $i === 0 ? 100 : 97,
        'status' => $i === 0 ? 'succeeded' : 'failed', 'deployed_at' => now()->subHour(),
        'last_error' => $i === 0 ? null : 'PowerDNS API returned 502 Bad Gateway while patching the zone; the previous valid zone stays active and the job will retry with backoff.',
    ]);
}
foreach (['waf_block_sqli', 'rate_limit_client', 'origin_timeout', 'geo_block', 'bad_bot', 'rate_limit_domain', 'waf_block_xss', 'tls_handshake_fail', 'origin_5xx', 'ip_deny'] as $i => $reason) {
    SecurityEvent::query()->create(['domain_id' => $showcase->id, 'reason_code' => $reason, 'occurred_at' => now()->subMinutes($i * 7), 'details' => []]);
}

$statuses = ['succeeded', 'failed', 'pending', 'running'];
$spread = array_values($domains);
foreach (range(1, 45) as $n) {
    $status = $statuses[$n % 4];
    $target = $spread[intdiv($n, 6) % count($spread)];
    Operation::query()->create([
        'type' => ['dns.zone_reconcile', 'edge.domain_reconcile', 'cache.purge', 'tls.issue', 'domain.nameservers_verify', 'dns.zone_import'][$n % 6],
        'status' => $status, 'input' => ['domain_id' => $target->id], 'domain_id' => $target->id, 'actor_id' => $admin->id,
        'error' => $status === 'failed' ? "Edge edge-with-a-very-long-point-of-presence-name rejected revision {$n}: checksum mismatch after activation, previous valid state retained." : null,
    ]);
}
foreach (range(1, 40) as $n) {
    AuditLog::record($admin, ['domain.updated', 'tls.mode_updated', 'user.created', 'cache.purged'][$n % 4], $showcase, ['revision' => $n], '127.0.0.1')
        ->forceFill(['created_at' => now()->subMinutes($n + 5)])->save();
}

// Changes the platform made on its own: events in the history, never configuration revisions.
AuditLog::record(null, 'security.automatic_state_changed', $showcase, ['from' => 'normal', 'to' => 'suspected', 'cause' => 'event_rate', 'events_in_window' => 64, 'window_minutes' => 5, 'overlay_sequence' => 1])
    ->forceFill(['created_at' => now()->subMinutes(2)])->save();
AuditLog::record(null, 'security.automatic_state_changed', $showcase, ['from' => 'suspected', 'to' => 'normal', 'cause' => 'quiet_period', 'quiet_minutes' => 10, 'overlay_sequence' => 2])
    ->forceFill(['created_at' => now()->subMinute()])->save();

// Notifications: the switch for domain users is on, with an administrator channel, a user channel, and a paused channel whose reason is long.
$notifications = SystemSetting::query()->findOrFail('notifications');
$notifications->update(['values' => [...$notifications->values, 'domain_user_channels' => true]]);
foreach ([
    [null, 'telegram', 'Operations chat for the whole platform on call rotation', 'Telegram chat -1001234567890', null, 'paused', 'Paused so the browser suite makes no outbound calls: the stack runs queues synchronously.'],
    [null, 'webhook', 'Pager bridge', 'Webhook to alerts.example.com', null, 'paused', 'Paused so the browser suite makes no outbound calls: the stack runs queues synchronously.'],
    [$user->id, 'slack', 'Team channel', 'Slack webhook on hooks.slack.com, ending wxyz', ['certificate.failed', 'dns.deployment_failed'], 'active', null],
    [$user->id, 'discord', 'Weekend alerts with a deliberately long channel name to check truncation', 'Discord webhook on discord.com, ending 6789', ['certificate.expiring'], 'paused',
        'Paused after 10 failed deliveries in a row. Last error: Discord answered HTTP 404, so the webhook was probably deleted on the Discord side.'],
] as [$owner, $type, $name, $hint, $events, $status, $reason]) {
    $channel = new NotificationChannel(['user_id' => $owner, 'type' => $type, 'name' => $name, 'destination_hint' => $hint, 'events' => $events, 'status' => $status,
        'consecutive_failures' => $status === 'paused' ? 10 : 0, 'paused_reason' => $reason, 'last_error' => $reason === null ? null : 'Discord answered HTTP 404.']);
    $channel->storeSecrets(['webhook_url' => 'https://example.invalid/never-used', 'bot_token' => 'x', 'chat_id' => '1']);
    $channel->save();
}

// Custom rules on the first assigned domain: a long name, IPv6, a list, and a five-condition rule, each with a different action.
$rulesDomain = Domain::query()->whereHas('users', fn ($users) => $users->whereKey($user->id))->orderBy('id')->firstOrFail();
foreach ([
    ['Block the admin area for visitors from outside the European Union with a deliberately long name', 'block', 10, [['field' => 'path', 'operator' => 'starts_with', 'value' => '/admin'], ['field' => 'continent', 'operator' => 'not_equals', 'value' => 'EU']]],
    ['Allow the office network', 'allow', 20, [['field' => 'client_ip', 'operator' => 'in_list', 'value' => ['203.0.113.0/24', '2001:db8:abcd::/48']]]],
    [null, 'challenge', 30, [['field' => 'path', 'operator' => 'starts_with', 'value' => '/login'], ['field' => 'method', 'operator' => 'equals', 'value' => 'POST'], ['field' => 'header', 'name' => 'x-api-key', 'operator' => 'equals', 'value' => 'none'], ['field' => 'cookie_present', 'operator' => 'equals', 'name' => 'session', 'value' => false], ['field' => 'body_size', 'operator' => 'less_than', 'value' => 4096]]],
] as [$name, $action, $priority, $conditions]) {
    $rulesDomain->securityRules()->create(['name' => $name, 'action' => $action, 'priority' => $priority, 'conditions' => $conditions, 'fingerprint' => SecurityRuleData::fingerprint($conditions, $action)]);
}

// Log push: the switch is on, and the first assigned domain has a healthy destination and a failing one with a long error.
$logPush = SystemSetting::query()->findOrFail('log_push');
$logPush->update(['values' => [...$logPush->values, 'domain_user_log_push' => true]]);
$pushDomain = Domain::query()->whereHas('users', fn ($users) => $users->whereKey($user->id))->orderBy('id')->firstOrFail();
foreach ([
    ['SIEM at the security team with a long descriptive name to check truncation', 'https', 'HTTPS to logs.security-team.example-company.co.uk', ['http_requests', 'security_events'], 0, null, 48210, 12_400_000, 41],
    ['Archive bucket', 's3', 'S3 bucket cdn-logs on s3.eu-west-1.amazonaws.com', ['dns_queries'], 6, 'The destination answered HTTP 503. The receiver at logs.example.com is overloaded, so batches are retried with a growing delay and nothing is skipped.', 912, 220_000, 3600],
] as [$name, $kind, $hint, $datasets, $failures, $error, $rows, $bytes, $lag]) {
    $destination = new LogPushDestination(['domain_id' => $pushDomain->id, 'kind' => $kind, 'name' => $name, 'datasets' => $datasets, 'destination_hint' => $hint, 'status' => 'active', 'cursors' => [],
        'consecutive_failures' => $failures, 'failures_total' => $failures, 'rows_pushed' => $rows, 'bytes_pushed' => $bytes, 'lag_seconds' => $lag, 'last_error' => $error,
        'last_delivery_at' => now()->subMinutes($failures > 0 ? 40 : 1), 'next_run_at' => $failures > 0 ? now()->addMinutes(20) : null]);
    $destination->storeSecrets(['url' => 'https://example.invalid/never-used', 'auth' => 'bearer', 'bearer_token' => 'never-used-token']);
    $destination->save();
}
echo "browser seed complete\n";
