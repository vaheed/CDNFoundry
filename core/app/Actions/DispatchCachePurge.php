<?php

namespace App\Actions;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\CachePurge;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\EdgeTask;
use App\Models\Operation;
use App\Models\User;
use App\Support\CacheKey;
use App\Support\CachePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DispatchCachePurge
{
    public const MAX_PENDING_PER_DOMAIN = 1000;

    public static function execute(Domain $domain, string $type, array $urls, User $actor, ?string $ipAddress): CachePurge
    {
        $settings = CachePolicy::normalize($domain->cache_settings);
        $keys = $type === 'urls' ? collect($urls)
            ->map(fn (string $url): string => CacheKey::fromUrl($domain, $url, $settings['query_policy'], $settings['query_parameters']))
            ->unique()->values()->all() : null;

        $purge = DB::transaction(function () use ($domain, $type, $keys, $actor, $ipAddress): CachePurge {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $outstanding = CachePurge::query()->where('domain_id', $locked->id)->whereIn('status', ['pending', 'running'])->count();
            if ($outstanding >= self::MAX_PENDING_PER_DOMAIN) {
                throw ValidationException::withMessages(['type' => 'This domain already has the maximum 1,000 outstanding purge requests. Wait for delivery before submitting another purge.']);
            }
            if ($type === 'all') {
                $locked->increment('cache_epoch');
            }
            $sequence = DB::getDriverName() === 'pgsql'
                ? (int) DB::selectOne("SELECT nextval('cache_purge_sequence') AS number")->number
                : (int) CachePurge::query()->max('sequence') + 1;
            $operation = Operation::query()->create([
                'actor_id' => $actor->id, 'domain_id' => $locked->id, 'type' => 'cache.purge', 'status' => 'running',
                'started_at' => now(), 'input' => ['domain_id' => $locked->id, 'type' => $type, 'url_count' => count($keys ?? [])],
            ]);
            $purge = CachePurge::query()->create([
                'domain_id' => $locked->id, 'requested_by' => $actor->id, 'type' => $type, 'sequence' => $sequence,
                'operation_id' => $operation->id, 'cache_epoch' => $locked->cache_epoch, 'cache_keys' => $keys, 'status' => 'pending',
            ]);
            foreach (Edge::query()->where('enabled', true)->whereNull('identity_revoked_at')->cursor() as $edge) {
                EdgeTask::query()->create([
                    'id' => (string) Str::uuid(), 'edge_id' => $edge->id, 'cache_purge_id' => $purge->id,
                    'type' => 'cache_purge', 'payload' => [
                        'purge_id' => $sequence, 'domain_id' => $locked->id, 'domain' => $locked->name, 'type' => $type,
                        'cache_epoch' => $locked->cache_epoch, 'cache_keys' => $keys,
                    ], 'status' => 'pending',
                ]);
            }
            $purge->update(['status' => $purge->tasks()->exists() ? 'running' : 'succeeded']);
            AuditLog::record($actor, 'cache.purge_requested', $purge, ['type' => $type, 'url_count' => count($keys ?? [])], $ipAddress);

            return $purge;
        });
        if ($type === 'all') {
            ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();
        }

        return $purge;
    }
}
