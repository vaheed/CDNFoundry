<?php

namespace App\Filament\Domain\Resources\Domains;

use App\Enums\DomainLifecycleState;
use App\Http\Controllers\CacheController;
use App\Models\Domain;
use App\Models\EdgeRevision;
use App\Models\Operation;
use App\Models\PlatformDnsSetting;
use App\Support\EdgeRoutingCompiler;
use App\Support\IncidentProfile;
use App\Support\PlatformSettings;
use App\Support\SecurityAlertData;
use App\Support\SecurityConfig;

/**
 * Builds the rows shown on the domain detail page. Each section is a titled list of label and value rows:
 * kind is text, mono, badge, list, or time; a list collapses after `collapse` items. A section names the page actions
 * that change it, and a row may name the one action that changes that row; the page shows only the visible ones.
 */
class DomainDetailSections
{
    /** An icon marks every control as an action, so it never reads like the values beside it. */
    private const ICONS = [
        'reconcileDns' => 'phosphor-arrows-clockwise',
        'importZone' => 'phosphor-upload-simple',
        'exportZone' => 'phosphor-download-simple',
        'proxyDefaults' => 'phosphor-sliders-horizontal',
        'startMaintenance' => 'phosphor-wrench',
        'endMaintenance' => 'phosphor-wrench',
        'rollbackProxy' => 'phosphor-arrow-counter-clockwise',
        'moveEdgePool' => 'phosphor-arrows-left-right',
        'cacheSettings' => 'phosphor-sliders-horizontal',
        'purgeCache' => 'phosphor-broom',
        'developmentMode' => 'phosphor-code',
        'disableDevelopmentMode' => 'phosphor-code',
        'tlsMode' => 'phosphor-lock',
        'uploadCertificate' => 'phosphor-file-arrow-up',
        'renewManagedCertificate' => 'phosphor-arrows-clockwise',
        'reissueManagedCertificate' => 'phosphor-arrows-clockwise',
        'deleteCustomCertificate' => 'phosphor-trash',
        'quarantineSecurity' => 'phosphor-prohibit',
        'returnSecurityToNormal' => 'phosphor-shield-check',
    ];

    /**
     * Overview columns of compact cards. Rows that have nothing to say and no explicit empty text are left out.
     *
     * @return array{left: array<int, array{title: string, rows: array<int, array<string, mixed>>}>, right: array<int, array{title: string, rows: array<int, array<string, mixed>>}>}
     */
    public static function overview(Domain $domain): array
    {
        $visible = fn (array $section): array => ['actions' => [], ...$section, 'rows' => array_values(array_filter(
            $section['rows'],
            fn (array $row): bool => ! ($row['value'] === null || $row['value'] === '' || $row['value'] === []) || $row['badge'] !== null || $row['empty'] !== null,
        ))];

        return [
            'left' => array_map($visible, [self::delegation($domain), self::dns($domain), self::delivery($domain)]),
            'right' => array_map($visible, [self::protection($domain), self::tls($domain), self::cache($domain)]),
        ];
    }

    private static function delegation(Domain $domain): array
    {
        $verification = $domain->nameservers_verified_by !== null ? null : self::latestNameserverVerification($domain);
        $expiry = $domain->registrationExpiry();

        return ['title' => 'Delegation', 'rows' => [
            self::row('Label', $domain->display_name && $domain->display_name !== $domain->name ? $domain->display_name : null, 'text'),
            self::row('Nameservers', $domain->assignedNameservers() ?: null, 'list', copy: true, empty: 'Assigned after the platform reads your delegation'),
            self::row('Verified', $domain->nameservers_verified_at, 'time', empty: 'Not verified'),
            self::row('Claim', $domain->claimStatus(), 'badge'),
            self::row('Claim expires', $domain->nameservers_verified_at === null ? $domain->claim_expires_at : null, 'time'),
            self::row('Last check', $domain->nameservers_verified_by !== null ? 'force_verified' : $verification?->status, 'badge'),
            self::row('Check error', $domain->nameservers_verified_by !== null ? null : $verification?->error, 'text'),
            self::row('Checked', $domain->ns_checked_at, 'time'),
            self::row('Next check', $domain->ns_check_next_at !== null && in_array($domain->lifecycle_state, [DomainLifecycleState::PendingVerification, DomainLifecycleState::Active], true) ? $domain->ns_check_next_at : null, 'time'),
            self::row('Seen at registrar', $domain->ns_check_result === 'mismatch' ? $domain->ns_observed : null, 'list'),
            self::row('Registration expires', $expiry['label'] ?? null, 'badge', empty: 'Unknown', help: $domain->registration_expires_at?->diffForHumans(), tone: $expiry === null ? null : ($expiry['tone'] === 'neutral' ? 'neutral' : $expiry['tone'])),
        ]];
    }

    private static function delivery(Domain $domain): array
    {
        $placement = $domain->edgePlacement()->with(['activePool', 'targetPool'])->first();
        $pool = $placement?->state === 'draining' ? $placement->targetPool : $placement?->activePool;
        $settings = PlatformDnsSetting::query()->find(1);
        $timezone = app(PlatformSettings::class);
        $revisions = EdgeRevision::query()->with('author')->where('domain_id', $domain->id)->where('status', 'validated')->latest('revision')->limit(10)->get()
            ->map(fn (EdgeRevision $revision): string => "#{$revision->revision}   ".$timezone->formatTimestamp($revision->created_at, 'Y-m-d H:i:s')
                .'   '.($revision->author?->name ?? 'Platform').($revision->change_summary === null ? '' : '   '.$revision->change_summary))->all();

        return ['title' => 'Delivery', 'actions' => [
            self::action('proxyDefaults', 'Proxy settings'),
            self::action('startMaintenance', 'Maintenance', state: false), self::action('endMaintenance', 'Maintenance', state: true),
            self::action('rollbackProxy', 'Roll back', menu: true), self::action('moveEdgePool', 'Move service pool', menu: true),
        ], 'rows' => [
            self::row('Placement', $placement?->state, 'badge', empty: 'Not placed'),
            self::row('Pool', $placement?->activePool?->name, 'mono'),
            self::row('Moving to', $placement?->targetPool?->name, 'mono'),
            self::row('Cells', self::cellAssignments($domain, 'activeCell') ?: null, 'list', collapse: 3),
            self::row('Target cells', self::cellAssignments($domain, 'targetCell') ?: null, 'list', collapse: 3),
            self::row('DNS target', $pool !== null && $settings !== null ? EdgeRoutingCompiler::poolHostname($settings, $pool) : null, 'mono', copy: true, empty: 'Waiting for placement'),
            self::row('Revisions', $revisions ?: null, 'list', collapse: 2),
            self::row('Proxy', self::proxySettings($domain->proxy_settings), 'chips'),
            self::row('Last failure', $placement?->last_error, 'text'),
        ]];
    }

    private static function dns(Domain $domain): array
    {
        $timezone = app(PlatformSettings::class);
        $deployments = $domain->dnsDeployments()->with('cluster')->orderBy('dns_cluster_id')->get();
        $rows = [];
        foreach ($deployments as $deployment) {
            $rows[] = self::row(
                $deployment->cluster->name,
                ['Desired #'.$deployment->desired_revision, 'Active #'.$deployment->deployed_revision],
                'chips',
                help: $deployment->deployed_at === null ? null : 'Deployed '.$timezone->formatTimestamp($deployment->deployed_at, 'Y-m-d H:i:s'),
                badge: $deployment->status,
            );
            if (auth()->user()?->isAdmin() === true && filled($deployment->last_error)) {
                $rows[] = self::row($deployment->cluster->name.' error', $deployment->last_error, 'text');
            }
        }

        return ['title' => 'DNS deployment', 'actions' => [self::action('reconcileDns', 'Reconcile'), self::action('importZone', 'Import zone'), self::action('exportZone', 'Export zone')],
            'rows' => $rows ?: [self::row('Clusters', null, 'text', empty: 'Not deployed')]];
    }

    private static function cache(Domain $domain): array
    {
        $settings = $domain->cache_settings ?? CacheController::defaults();

        return ['title' => 'Cache', 'actions' => [
            self::action('cacheSettings', 'Settings'), self::action('purgeCache', 'Purge'),
            self::action('developmentMode', 'Development mode', state: false), self::action('disableDevelopmentMode', 'Development mode', state: true),
        ], 'rows' => [
            self::row('Policy', [
                $settings['enabled'] ? 'Enabled' : 'Disabled',
                'Edge '.(int) $settings['edge_ttl_seconds'].'s',
                'Browser '.(int) $settings['browser_ttl_seconds'].'s',
                self::bytes((int) $settings['maximum_object_bytes']).' max',
                $settings['respect_origin_headers'] ? 'Origin headers respected' : 'Configured TTL enforced',
            ], 'chips'),
            self::row('Purge epoch', (string) $domain->cache_epoch, 'mono'),
            self::row('Development mode until', $domain->cache_development_mode_until?->isFuture() ? $domain->cache_development_mode_until : null, 'time'),
        ]];
    }

    private static function tls(Domain $domain): array
    {
        $certificate = $domain->activeTlsCertificate;
        $order = $domain->latestTlsOrder;

        return ['title' => 'TLS', 'actions' => [
            self::action('tlsMode', 'Mode'), self::action('uploadCertificate', 'Upload certificate'),
            self::action('renewManagedCertificate', 'Renew', menu: true), self::action('reissueManagedCertificate', 'Reissue', menu: true),
            self::action('deleteCustomCertificate', 'Remove custom certificate', menu: true),
        ], 'rows' => [
            self::row('Mode', $domain->tls_mode, 'badge'),
            self::row('Certificate', $certificate?->status, 'badge', empty: 'Pending managed issuance'),
            self::row('Names', $certificate?->names ?: null, 'list', collapse: 3),
            self::row('Expires', $certificate?->expires_at, 'time'),
            self::row('SHA-256', $certificate?->fingerprint_sha256, 'code', copy: true),
            self::row('Failure', $certificate?->last_error, 'text'),
            self::row('Managed order', $order?->status, 'badge'),
            self::row('Order names', $order?->names ?: null, 'list', collapse: 3),
            self::row('ACME failure', $order?->last_error, 'text'),
        ]];
    }

    private static function protection(Domain $domain): array
    {
        $timezone = app(PlatformSettings::class);
        $limits = SecurityConfig::compile($domain)['limits'];
        $events = $domain->securityEvents()->latest('occurred_at')->limit(10)->get()
            ->map(fn ($event): string => self::reason((string) $event->reason_code).'   '.$timezone->formatTimestamp($event->occurred_at, 'Y-m-d H:i:s'))->all();
        $alert = $domain->security_alert;
        $credential = $domain->credential_check;
        $sensitive = $domain->sensitive_data_check;
        $ai = $domain->ai_protection;
        $schema = $domain->api_schema;
        $plural = fn (int $count, string $word): string => $count.' '.str($word)->plural($count);
        $setUp = fn (mixed $settings): string => $settings === null ? 'Set up' : 'Edit';

        return ['title' => 'Protection', 'actions' => [
            self::action('quarantineSecurity', 'Quarantine', menu: true), self::action('returnSecurityToNormal', 'Return to normal', menu: true),
        ], 'rows' => [
            self::row('Under attack mode', IncidentProfile::active($domain) ? 'On until '.$timezone->formatTimestamp($domain->incident_expires_at, 'Y-m-d H:i') : 'Off', 'text'),
            self::row('State', $domain->effectiveSecurityState(), 'badge'),
            self::row('Profile', ($domain->security_settings ?? SecurityConfig::defaults())['profile'], 'badge', action: self::action('securitySettings', 'Edit')),
            self::row('Limits', [
                "{$limits['requests_per_second']} req/s",
                "{$limits['connections_per_client']} per client",
                "{$limits['connections_per_domain']} per domain",
                "{$limits['origin_max_connections']} to origin",
                self::bytes((int) $limits['maximum_request_body_size']).' body',
            ], 'chips'),
            self::row('Firewall (WAF)', match ($domain->waf_profile) {
                'off' => 'Off', 'monitor' => 'Observe', 'balanced' => 'Recommended', 'strict' => 'High sensitivity', default => $domain->waf_profile,
            }, 'text', action: self::action('wafProfile', 'Edit')),
            self::row('Security alert', $alert === null ? 'Off' : number_format((int) $alert['threshold']).' blocked in '.SecurityAlertData::PERIODS[$alert['period_minutes']], 'text', action: self::action('securityAlert', $setUp($alert))),
            self::row('Credential check', $credential === null ? 'Off' : $plural(count($credential['login_paths']), 'login path'), 'text', action: self::action('credentialCheck', $setUp($credential))),
            self::row('Sensitive data', $sensitive === null ? 'Off' : collect($sensitive['kinds'])->map(fn (string $kind): string => ['card' => 'Cards', 'iban' => 'IBANs', 'private_key' => 'Private keys', 'api_key' => 'API keys'][$kind] ?? $kind)->implode(', '), 'text', action: self::action('sensitiveDataCheck', $setUp($sensitive))),
            self::row('AI protection', $ai === null ? 'Off' : $plural(count($ai['endpoints']), 'endpoint'), 'text', action: self::action('aiProtection', $setUp($ai))),
            self::row('API schema', $schema === null ? 'Off' : ($schema['title'] ?: 'Untitled').', '.$plural(count($schema['operations']), 'operation'), 'text', action: self::action('apiSchema', $setUp($schema))),
            self::row('Client-side', $domain->client_security === null ? 'Off' : 'Reporting scripts', 'text', action: self::action('clientSecurity', $setUp($domain->client_security))),
            self::row('Recent events', $events ?: null, 'list', collapse: 3),
        ]];
    }

    /** A page action to show on a section or a row. `state` marks a toggle shown as On or Off; `menu` puts it in the section's menu. */
    private static function action(string $name, string $label, ?bool $state = null, bool $menu = false): array
    {
        $icon = self::ICONS[$name] ?? (in_array($label, ['Set up'], true) ? 'phosphor-plus' : 'phosphor-pencil-simple');

        return ['name' => $name, 'label' => $label, 'state' => $state, 'menu' => $menu, 'icon' => $icon];
    }

    private static function bytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 && $bytes % 1073741824 === 0 => ($bytes / 1073741824).' GiB',
            $bytes >= 1048576 => rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.').' MiB',
            $bytes >= 1024 => rtrim(rtrim(number_format($bytes / 1024, 1), '0'), '.').' KiB',
            default => $bytes.' B',
        };
    }

    /** A security event reason in words: waf_block_sqli reads "WAF block sqli", rate_limit_client "Rate limit client". */
    private static function reason(string $code): string
    {
        return str($code)->replace('_', ' ')->ucfirst()->replace(['Waf ', 'waf ', 'sqli', 'xss', 'rce', 'Ip '], ['WAF ', 'WAF ', 'SQL injection', 'XSS', 'command injection', 'IP '])->toString();
    }

    private static function row(string $label, mixed $value, string $kind, bool $copy = false, ?string $empty = null, ?int $collapse = null, ?string $help = null, ?string $badge = null, ?string $tone = null, ?array $action = null): array
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format(DATE_ATOM);
        }

        return ['label' => $label, 'value' => $value, 'kind' => $kind, 'copy' => $copy, 'empty' => $empty, 'collapse' => $collapse, 'help' => $help, 'badge' => $badge, 'tone' => $tone, 'action' => $action];
    }

    private static function latestNameserverVerification(Domain $domain): ?Operation
    {
        return Operation::query()->where('type', 'domain.nameservers_verify')
            ->where('input->domain_id', $domain->id)->latest()->first();
    }

    /** @return array<int, string> */
    private static function cellAssignments(Domain $domain, string $relation): array
    {
        return $domain->edgeCells()->with(['edge:id,name', $relation.':id,name'])->orderBy('edge_id')->orderBy('replica')->get()
            ->filter(fn ($placement): bool => $placement->{$relation} !== null)
            ->map(fn ($placement): string => sprintf('%s replica %d: %s (%s)', $placement->edge->name, $placement->replica, $placement->{$relation}->name, str_replace('_', ' ', (string) $placement->state)))->all();
    }

    /** @return array<int, string> */
    private static function proxySettings(mixed $settings): array
    {
        if (! is_array($settings)) {
            return ['Platform defaults'];
        }

        $versions = collect($settings['http_versions'] ?? [])->map(fn (mixed $version): string => 'HTTP/'.(string) $version)->implode(' + ');

        return [
            ($settings['enabled'] ?? true) ? 'Enabled' : 'Disabled',
            $versions !== '' ? $versions : 'No HTTP versions selected',
            ($settings['redirect_https'] ?? false) ? 'HTTPS redirect on' : 'HTTPS redirect off',
            (int) ($settings['retry_count'] ?? 0).' origin retries',
            is_array($settings['maintenance'] ?? null) ? 'Maintenance on' : 'Maintenance off',
        ];
    }
}
