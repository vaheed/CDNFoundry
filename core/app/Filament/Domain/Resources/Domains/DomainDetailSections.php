<?php

namespace App\Filament\Domain\Resources\Domains;

use App\Enums\DomainLifecycleState;
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
    /**
     * Overview columns of compact cards. Rows that have nothing to say and no explicit empty text are left out.
     *
     * @return array{left: array<int, array{title: string, rows: array<int, array<string, mixed>>}>, right: array<int, array{title: string, rows: array<int, array<string, mixed>>}>}
     */
    public static function overview(Domain $domain): array
    {
        $visible = fn (array $section): array => [...$section, 'rows' => array_values(array_filter(
            $section['rows'],
            fn (array $row): bool => ! ($row['value'] === null || $row['value'] === '' || $row['value'] === []) || $row['badge'] !== null || $row['empty'] !== null,
        ))];

        return [
            'left' => array_map($visible, [self::delegation($domain), self::dns($domain), self::delivery($domain)]),
            'right' => array_map($visible, [self::tls($domain), self::cache($domain), self::security($domain)]),
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
            self::row('Registration expires', $expiry['label'] ?? null, 'badge', empty: 'Unknown', help: $domain->registration_expires_at === null ? null : app(PlatformSettings::class)->formatTimestamp($domain->registration_expires_at, 'Y-m-d'), tone: $expiry === null ? null : ($expiry['tone'] === 'neutral' ? 'neutral' : $expiry['tone'])),
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

        return ['title' => 'Delivery', 'rows' => [
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
                help: $deployment->deployed_at === null ? null : 'Deployed '.$timezone->formatTimestamp($deployment->deployed_at, 'Y-m-d H:i:s T'),
                badge: $deployment->status,
            );
            if (auth()->user()?->isAdmin() === true && filled($deployment->last_error)) {
                $rows[] = self::row($deployment->cluster->name.' error', $deployment->last_error, 'text');
            }
        }

        return ['title' => 'DNS deployment', 'rows' => $rows ?: [self::row('Clusters', null, 'text', empty: 'Not deployed')]];
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
            self::row('Purge epoch', (string) $domain->cache_epoch, 'mono'),
            self::row('Development mode until', $domain->cache_development_mode_until?->isFuture() ? $domain->cache_development_mode_until : null, 'time'),
        ]];
    }

    private static function tls(Domain $domain): array
    {
        $certificate = $domain->activeTlsCertificate;
        $order = $domain->latestTlsOrder;

        return ['title' => 'TLS', 'rows' => [
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

    private static function security(Domain $domain): array
    {
        $timezone = app(PlatformSettings::class);
        $limits = SecurityConfig::compile($domain)['limits'];
        $events = $domain->securityEvents()->latest('occurred_at')->limit(10)->get()
            ->map(fn ($event): string => "{$event->reason_code}   ".$timezone->formatTimestamp($event->occurred_at, 'Y-m-d H:i:s T'))->all();

        return ['title' => 'Security', 'rows' => [
            self::row('Profile', ($domain->security_settings ?? SecurityConfig::defaults())['profile'], 'badge'),
            self::row('State', $domain->security_state, 'badge'),
            self::row('WAF', match ($domain->waf_profile) {
                'off' => 'Off',
                'monitor' => 'Observe',
                'balanced' => 'Recommended',
                'strict' => 'High sensitivity',
                default => $domain->waf_profile,
            }, 'badge'),
            self::row('Limits', [
                "{$limits['requests_per_second']} req/s",
                "{$limits['connections_per_client']} client connections",
                "{$limits['connections_per_domain']} domain connections",
                "{$limits['origin_max_connections']} origin connections",
                "{$limits['maximum_request_body_size']} body bytes",
            ], 'chips'),
            self::row('Recent events', $events ?: null, 'list', collapse: 3),
        ]];
    }

    private static function row(string $label, mixed $value, string $kind, bool $copy = false, ?string $empty = null, ?int $collapse = null, ?string $help = null, ?string $badge = null, ?string $tone = null): array
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format(DATE_ATOM);
        }

        return ['label' => $label, 'value' => $value, 'kind' => $kind, 'copy' => $copy, 'empty' => $empty, 'collapse' => $collapse, 'help' => $help, 'badge' => $badge, 'tone' => $tone];
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
