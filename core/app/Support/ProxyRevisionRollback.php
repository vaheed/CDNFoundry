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

final class ProxyRevisionRollback
{
    public static function apply(Domain $domain, EdgeRevision $prior, User $actor, ?string $ipAddress): Domain
    {
        return DB::transaction(function () use ($domain, $prior, $actor, $ipAddress): Domain {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $snapshot = $prior->snapshot;
            $hostnames = collect($snapshot['hostnames']);
            $wanted = $hostnames->pluck('hostname')->all();
            $locked->dnsRecords()->where('mode', 'proxied')->whereNotIn('name', $wanted)->delete();
            foreach ($hostnames as $hostname) {
                $origin = $hostname['origin'];
                $locked->dnsRecords()->where('name', $hostname['hostname'])->where('mode', '!=', 'proxied')->delete();
                DnsRecord::query()->updateOrCreate(
                    ['domain_id' => $locked->id, 'name' => $hostname['hostname'], 'mode' => 'proxied'],
                    [
                        'type' => $hostname['type'], 'content' => $origin['host'], 'ttl' => $hostname['ttl'],
                        'priority' => 0, 'weight' => 0, 'port' => 0, 'origin' => $origin, 'geo_config' => null,
                        'content_hash' => hash('sha256', json_encode($origin, JSON_THROW_ON_ERROR)),
                    ],
                );
            }
            $settings = is_array($snapshot['settings'] ?? null) ? $snapshot['settings'] : null;
            $cache = is_array($snapshot['cache'] ?? null) ? $snapshot['cache'] : null;
            $cacheSettings = $cache === null ? null : collect($cache)->only([
                'enabled', 'edge_ttl_seconds', 'browser_ttl_seconds', 'maximum_object_bytes',
                'respect_origin_headers', 'include_query_string', 'bypass_cookie_names', 'stale_if_error_seconds',
            ])->all();
            $developmentUntil = isset($cache['development_mode_until'])
                ? CarbonImmutable::createFromTimestamp((int) $cache['development_mode_until'])
                : null;
            if ($developmentUntil?->isPast()) {
                $developmentUntil = null;
            }
            $security = is_array($snapshot['security'] ?? null) ? $snapshot['security'] : null;
            $securitySettings = $security === null ? null : [
                'profile' => $security['profile'], 'quarantine_policy' => $security['quarantine_policy'],
                'allowed_methods' => $security['allowed_methods'], 'trusted_proxy_cidrs' => $security['trusted_proxy_cidrs'],
                'limits' => $security['configured_limits'] ?? $security['limits'],
            ];
            if ($security !== null) {
                $locked->securityRules()->delete();
                $now = now();
                SecurityRule::query()->insert(collect($security['rules'] ?? [])->map(fn (array $rule): array => [
                    'domain_id' => $locked->id, 'match_type' => $rule['match_type'], 'value' => $rule['value'],
                    'action' => $rule['action'], 'priority' => $rule['priority'], 'enabled' => true,
                    'note' => null, 'created_at' => $now, 'updated_at' => $now,
                ])->all());
            }
            $locked->update([
                'proxy_settings' => $settings, 'cache_settings' => $cacheSettings,
                'security_settings' => $securitySettings,
                'cache_epoch' => $locked->cache_epoch + 1, 'cache_development_mode_until' => $developmentUntil,
                'revision' => $locked->revision + 1,
            ]);
            AuditLog::record($actor, 'proxy.revision_rolled_back', $locked, ['from' => $prior->revision, 'revision' => $locked->revision], $ipAddress);

            return $locked;
        });
    }
}
