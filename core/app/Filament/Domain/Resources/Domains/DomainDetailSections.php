<?php

namespace App\Filament\Domain\Resources\Domains;

use App\Http\Controllers\CacheController;
use App\Models\Domain;
use App\Models\EdgeRevision;
use App\Models\Operation;
use App\Models\PlatformDnsSetting;
use App\Support\EdgeRoutingCompiler;
use App\Support\PlatformSettings;
use App\Support\SecurityConfig;

/**
 * Builds the rows shown on the domain detail page. Each section is a titled list of label and value rows:
 * kind is text, mono, badge, list, or time; a list collapses after `collapse` items.
 */
class DomainDetailSections
{
    /** @return array<int, array{title: string, rows: array<int, array<string, mixed>>}> */
    public static function for(Domain $domain, string $tab): array
    {
        return match ($tab) {
            'overview' => [self::identity($domain), self::delivery($domain)],
            'dns' => [self::dns($domain)],
            'cache' => [self::cache($domain)],
            'tls' => [self::tls($domain)],
            'security' => [self::security($domain)],
        };
    }

    private static function identity(Domain $domain): array
    {
        $verification = $domain->nameservers_verified_by !== null ? null : self::latestNameserverVerification($domain);

        return ['title' => 'Domain', 'rows' => [
            ...($domain->display_name && $domain->display_name !== $domain->name ? [self::row('Display label', $domain->display_name, 'text')] : []),
            self::row('Lifecycle', $domain->lifecycle_state->value, 'badge'),
            self::row('Desired revision', '#'.$domain->revision, 'mono'),
            self::row('Desired revision changed', $domain->revision_changed_at, 'time'),
            self::row('Assigned nameservers', $domain->assignedNameservers() ?: null, 'list', copy: true, empty: 'Assigned after the platform reads your current delegation'),
            self::row('Claim status', $domain->claimStatus(), 'badge'),
            self::row('Pending claim expires', $domain->claim_expires_at, 'time', empty: 'Previously verified domain'),
            self::row('Nameservers verified', $domain->nameservers_verified_at, 'time', empty: 'Pending'),
            self::row('Latest verification', $domain->nameservers_verified_by !== null ? 'force_verified' : $verification?->status, 'badge', empty: 'Not requested'),
            self::row('Verification error', $domain->nameservers_verified_by !== null ? null : $verification?->error, 'text'),
        ]];
    }

    private static function delivery(Domain $domain): array
    {
        $placement = $domain->edgePlacement()->with(['activePool', 'targetPool'])->first();
        $pool = $placement?->state === 'draining' ? $placement->targetPool : $placement?->activePool;
        $settings = PlatformDnsSetting::query()->find(1);
        $timezone = app(PlatformSettings::class);
        $revisions = EdgeRevision::query()->where('domain_id', $domain->id)->where('status', 'validated')->latest('revision')->limit(10)->get()
            ->map(fn (EdgeRevision $revision): string => "#{$revision->revision}   ".$timezone->formatTimestamp($revision->created_at, 'Y-m-d H:i:s T'))->all();

        return ['title' => 'Edge delivery', 'rows' => [
            self::row('Proxied hostnames', (string) $domain->dnsRecords()->where('mode', 'proxied')->count(), 'mono'),
            self::row('Active edge revision', $domain->active_edge_revision === null ? null : '#'.$domain->active_edge_revision, 'mono', empty: 'Not deployed'),
            self::row('Active revision created', EdgeRevision::query()->where('domain_id', $domain->id)->where('revision', $domain->active_edge_revision)->value('created_at'), 'time', empty: 'Not deployed'),
            self::row('Placement state', $placement?->state, 'badge', empty: 'Not placed'),
            self::row('Active service pool', $placement?->activePool?->name, 'mono'),
            self::row('Target service pool', $placement?->targetPool?->name, 'mono'),
            self::row('Active cells by edge', self::cellAssignments($domain, 'activeCell') ?: null, 'list'),
            self::row('Target cells by edge', self::cellAssignments($domain, 'targetCell') ?: null, 'list'),
            self::row('Service-pool DNS target', $pool !== null && $settings !== null ? EdgeRoutingCompiler::poolHostname($settings, $pool) : null, 'mono', copy: true, empty: 'Waiting for placement'),
            self::row('Validated revisions', $revisions ?: null, 'list', collapse: 3),
            self::row('Proxy defaults', self::proxySettings($domain->proxy_settings), 'chips'),
            self::row('Last deployment failure', $placement?->last_error, 'text'),
        ]];
    }

    private static function dns(Domain $domain): array
    {
        $timezone = app(PlatformSettings::class);
        $deployments = $domain->dnsDeployments()->with('cluster')->orderBy('dns_cluster_id')->get();
        $rows = [];
        foreach ($deployments as $deployment) {
            $rows[] = self::row($deployment->cluster->name, sprintf(
                'desired #%d, active #%d%s',
                $deployment->desired_revision,
                $deployment->deployed_revision,
                $deployment->deployed_at === null ? '' : ', '.$timezone->formatTimestamp($deployment->deployed_at),
            ), 'mono', badge: $deployment->status);
            if (auth()->user()?->isAdmin() === true && filled($deployment->last_error)) {
                $rows[] = self::row($deployment->cluster->name.' error', $deployment->last_error, 'text');
            }
        }

        return ['title' => 'Authoritative DNS deployment', 'rows' => $rows ?: [self::row('Deployments', null, 'text', empty: 'Not deployed')]];
    }

    private static function cache(Domain $domain): array
    {
        $settings = $domain->cache_settings ?? CacheController::defaults();

        return ['title' => 'Cache', 'rows' => [
            self::row('Policy', [
                $settings['enabled'] ? 'Enabled' : 'Disabled',
                'Edge '.(int) $settings['edge_ttl_seconds'].'s',
                'Browser '.(int) $settings['browser_ttl_seconds'].'s',
                number_format(((int) $settings['maximum_object_bytes']) / 1048576, 1).' MiB max',
                $settings['respect_origin_headers'] ? 'Origin headers respected' : 'Configured TTL enforced',
            ], 'chips'),
            self::row('Full-purge epoch', (string) $domain->cache_epoch, 'mono'),
            self::row('Development mode until', $domain->cache_development_mode_until, 'time', empty: 'Off'),
        ]];
    }

    private static function tls(Domain $domain): array
    {
        $certificate = $domain->activeTlsCertificate;
        $order = $domain->latestTlsOrder;

        return ['title' => 'TLS', 'rows' => [
            self::row('Mode', $domain->tls_mode, 'badge'),
            self::row('Certificate status', $certificate?->status, 'badge', empty: 'Pending managed issuance'),
            self::row('Covered names', $certificate?->names ?: null, 'list', collapse: 4),
            self::row('Expires', $certificate?->expires_at, 'time'),
            self::row('SHA-256 fingerprint', $certificate?->fingerprint_sha256, 'code', copy: true),
            self::row('Last failure', $certificate?->last_error, 'text'),
            self::row('Latest managed order', $order?->status, 'badge', empty: 'Not queued'),
            self::row('Requested names', $order?->names ?: null, 'list', collapse: 4),
            self::row('ACME failure', $order?->last_error, 'text'),
        ]];
    }

    private static function security(Domain $domain): array
    {
        $timezone = app(PlatformSettings::class);
        $limits = SecurityConfig::compile($domain)['limits'];
        $events = $domain->securityEvents()->latest('occurred_at')->limit(10)->get()
            ->map(fn ($event): string => "{$event->reason_code}   ".$timezone->formatTimestamp($event->occurred_at, 'Y-m-d H:i:s T'))->all();

        return ['title' => 'Security and DDoS readiness', 'rows' => [
            self::row('Configured profile', ($domain->security_settings ?? SecurityConfig::defaults())['profile'], 'badge'),
            self::row('Operational state', $domain->security_state, 'badge'),
            self::row('State changed', $domain->security_state_changed_at, 'time', empty: 'Never'),
            self::row('Web application firewall', match ($domain->waf_profile) {
                'off' => 'Off',
                'monitor' => 'Observe',
                'balanced' => 'Recommended',
                'strict' => 'High sensitivity',
                default => $domain->waf_profile,
            }, 'badge', help: 'Off does not inspect, Observe reports without blocking, Recommended blocks common attacks, and High sensitivity blocks more aggressively.'),
            self::row('Rules', (string) $domain->securityRules()->count(), 'mono'),
            self::row('Effective request/origin limits', [
                "{$limits['requests_per_second']} req/s",
                "{$limits['connections_per_client']} client connections",
                "{$limits['connections_per_domain']} domain connections",
                "{$limits['origin_max_connections']} origin connections",
                "{$limits['maximum_request_body_size']} body bytes",
            ], 'chips'),
            self::row('Recent reason codes', $events ?: null, 'list', collapse: 3),
        ]];
    }

    private static function row(string $label, mixed $value, string $kind, bool $copy = false, ?string $empty = null, ?int $collapse = null, ?string $help = null, ?string $badge = null): array
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format(DATE_ATOM);
        }

        return ['label' => $label, 'value' => $value, 'kind' => $kind, 'copy' => $copy, 'empty' => $empty, 'collapse' => $collapse, 'help' => $help, 'badge' => $badge];
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
            ->map(fn ($placement): string => sprintf('%s · replica %d · %s · %s', $placement->edge->name, $placement->replica, $placement->{$relation}->name, $placement->state))->all();
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
