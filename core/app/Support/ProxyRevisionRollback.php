<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\EdgeRevision;
use App\Models\SecurityRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Restores only customer-owned fields from a retained revision. Platform policy
 * that the compiler injects into snapshots (origin safety lists, effective
 * limits, pool profiles) is never copied back, and every restored value is
 * validated again against current rules. A revision that no longer passes is
 * refused as a whole.
 */
final class ProxyRevisionRollback
{
    private const PLATFORM_ORIGIN_KEYS = ['private_allowlist', 'blocked_networks', 'blocked_addresses'];

    public static function apply(Domain $domain, EdgeRevision $prior, User $actor, ?string $ipAddress): Domain
    {
        $plan = self::plan($prior->snapshot);

        return DB::transaction(function () use ($domain, $prior, $actor, $ipAddress, $plan): Domain {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $wanted = array_column($plan['records'], 'name');
            foreach ($wanted as $name) {
                // MX, TXT and other types may share the name; only a DNS-only address or alias record collides.
                abort_if($locked->dnsRecords()->where('name', $name)->where('mode', '!=', 'proxied')->whereIn('type', ['A', 'AAAA', 'CNAME'])->exists(), 409, "A DNS-only address or alias record now uses {$name}; remove it before rolling back.");
            }
            $locked->dnsRecords()->where('mode', 'proxied')->whereNotIn('name', $wanted)->delete();
            foreach ($plan['records'] as $record) {
                DnsRecord::query()->updateOrCreate(
                    ['domain_id' => $locked->id, 'name' => $record['name'], 'mode' => 'proxied'],
                    [
                        'type' => $record['type'], 'content' => $record['origin']['host'], 'ttl' => $record['ttl'],
                        'priority' => 0, 'weight' => 0, 'port' => 0, 'origin' => $record['origin'], 'geo_config' => null,
                        'content_hash' => hash('sha256', json_encode($record['origin'], JSON_THROW_ON_ERROR)),
                    ],
                );
            }
            $tlsMode = $plan['tls_mode'];
            $certificateId = match ($tlsMode) {
                'custom' => $locked->tlsCertificates()->where('kind', 'custom')->where('status', 'active')->where('expires_at', '>', now())->latest('activated_at')->value('id'),
                'managed' => $locked->tlsCertificates()->where('kind', 'managed')->where('status', 'active')->where('expires_at', '>', now())->latest('activated_at')->value('id'),
                default => $locked->active_tls_certificate_id,
            };
            abort_if($tlsMode === 'custom' && $certificateId === null, 409, 'The revision used a custom certificate that is no longer available.');
            $locked->securityRules()->delete();
            $now = now();
            SecurityRule::query()->insert(array_map(fn (array $rule): array => [
                ...$rule, 'domain_id' => $locked->id, 'enabled' => true, 'note' => null, 'created_at' => $now, 'updated_at' => $now,
            ], $plan['rules']));
            $locked->update([
                'proxy_settings' => $plan['proxy_settings'], 'cache_settings' => $plan['cache_settings'],
                'security_settings' => $plan['security_settings'], 'waf_profile' => $plan['waf_profile'],
                'tls_mode' => $tlsMode, 'active_tls_certificate_id' => $certificateId,
                'cache_epoch' => $locked->cache_epoch + 1, 'cache_development_mode_until' => $plan['development_until'],
                'revision' => $locked->revision + 1,
            ]);
            AuditLog::record($actor, 'proxy.revision_rolled_back', $locked, ['from' => $prior->revision, 'revision' => $locked->revision], $ipAddress);

            return $locked;
        });
    }

    /** @return array<string, mixed> */
    private static function plan(array $snapshot): array
    {
        $records = [];
        foreach ($snapshot['hostnames'] ?? [] as $hostname) {
            $origin = self::customerOrigin($hostname['origin'] ?? []);
            $origin = OriginData::validate($origin);
            OriginData::resolveAndValidate($origin['host']);
            if (is_array($origin['backup'])) {
                OriginData::resolveAndValidate($origin['backup']['host']);
            }
            $fields = Validator::make($hostname, [
                'hostname' => ['required', 'string', 'max:253'], 'type' => ['required', 'in:A,AAAA,CNAME'],
                'ttl' => ['required', 'integer', 'between:30,2147483647'],
            ])->validate();
            $records[] = ['name' => $fields['hostname'], 'type' => $fields['type'], 'ttl' => (int) $fields['ttl'], 'origin' => $origin];
        }

        $settings = $snapshot['settings'] ?? null;
        $proxy = is_array($settings) ? Validator::make($settings, [
            'enabled' => ['required', 'boolean'], 'redirect_https' => ['required', 'boolean'],
            'http_versions' => ['required', 'array', 'min:1', 'max:2'], 'http_versions.*' => ['in:1.1,2'],
            'retry_count' => ['required', 'integer', 'between:0,2'], 'maintenance' => ['nullable', 'array'],
            'maintenance.status' => ['required_with:maintenance', 'integer', 'in:503'],
            'maintenance.body' => ['required_with:maintenance', 'string', 'max:4096'],
        ])->validate() : null;

        $cache = is_array($snapshot['cache'] ?? null) ? $snapshot['cache'] : null;
        $cacheSettings = $cache === null ? null : Validator::make(
            array_intersect_key(CachePolicy::normalize($cache), CachePolicy::rules()),
            CachePolicy::rules(),
        )->validate();
        $developmentUntil = isset($cache['development_mode_until']) ? CarbonImmutable::createFromTimestamp((int) $cache['development_mode_until']) : null;
        if ($developmentUntil?->isPast()) {
            $developmentUntil = null;
        }

        $security = is_array($snapshot['security'] ?? null) ? $snapshot['security'] : null;
        $securitySettings = $security === null ? null : SecurityConfig::validateSettings([
            'profile' => $security['profile'] ?? null, 'quarantine_policy' => $security['quarantine_policy'] ?? null,
            'allowed_methods' => $security['allowed_methods'] ?? [], 'trusted_proxy_cidrs' => $security['trusted_proxy_cidrs'] ?? [],
            'limits' => $security['configured_limits'] ?? $security['limits'] ?? [],
        ]);
        $rules = array_map(function (array $rule): array {
            $valid = SecurityConfig::validateRule($rule);

            return ['match_type' => $valid['match_type'], 'value' => $valid['value'], 'action' => $valid['action'], 'priority' => $valid['priority']];
        }, $security['rules'] ?? []);

        $waf = $snapshot['waf']['name'] ?? 'off';
        abort_unless(array_key_exists($waf, ManagedWaf::PROFILES), 422, 'The revision uses an unknown WAF profile.');
        $tlsMode = $snapshot['tls']['mode'] ?? 'managed';
        abort_unless(in_array($tlsMode, ['managed', 'custom', 'disabled'], true), 422, 'The revision uses an unknown TLS mode.');

        return [
            'records' => $records, 'proxy_settings' => $proxy, 'cache_settings' => $cacheSettings, 'security_settings' => $securitySettings,
            'rules' => $rules, 'waf_profile' => $waf, 'tls_mode' => $tlsMode, 'development_until' => $developmentUntil,
        ];
    }

    private static function customerOrigin(array $origin): array
    {
        $origin = array_diff_key($origin, array_flip(self::PLATFORM_ORIGIN_KEYS));
        if (is_array($origin['backup'] ?? null)) {
            $origin['backup'] = array_diff_key($origin['backup'], array_flip(self::PLATFORM_ORIGIN_KEYS));
        }

        return $origin;
    }
}
