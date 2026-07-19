<?php

namespace App\Http\Controllers;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\CachePurge;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\EdgeTask;
use App\Support\CacheKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CacheController extends Controller
{
    public function show(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => $this->state($domain)]);
    }

    public function update(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $data = $request->validate($this->rules());
        DB::transaction(function () use ($domain, $data, $request): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $locked->update(['cache_settings' => $data, 'revision' => $locked->revision + 1]);
            AuditLog::record($request->user(), 'cache.settings_updated', $locked, ['revision' => $locked->revision], $request->ip());
        });
        ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();

        return response()->json(['data' => $this->state($domain->refresh())], 202);
    }

    public function enableDevelopmentMode(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $data = $request->validate(['duration_minutes' => ['required', 'integer', 'between:1,1440']]);
        DB::transaction(function () use ($domain, $data, $request): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $locked->update(['cache_development_mode_until' => now()->addMinutes($data['duration_minutes']), 'revision' => $locked->revision + 1]);
            AuditLog::record($request->user(), 'cache.development_mode_enabled', $locked, ['expires_at' => $locked->cache_development_mode_until?->toIso8601String()], $request->ip());
        });
        ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();

        return response()->json(['data' => $this->state($domain->refresh())], 202);
    }

    public function disableDevelopmentMode(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        DB::transaction(function () use ($domain, $request): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $locked->update(['cache_development_mode_until' => null, 'revision' => $locked->revision + 1]);
            AuditLog::record($request->user(), 'cache.development_mode_disabled', $locked, [], $request->ip());
        });
        ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();

        return response()->json(['data' => $this->state($domain->refresh())], 202);
    }

    public function purge(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $data = $request->validate(['type' => ['required', 'in:all,urls'], 'urls' => ['required_if:type,urls', 'prohibited_if:type,all', 'array', 'min:1', 'max:100'], 'urls.*' => ['string', 'max:2048', 'distinct']]);
        abort_if(strlen($request->getContent()) > 128 * 1024, 413, 'The purge payload exceeds 128 KiB.');
        $settings = $domain->cache_settings ?? self::defaults();
        $keys = $data['type'] === 'urls' ? collect($data['urls'])->map(fn (string $url): string => CacheKey::fromUrl($domain, $url, $settings['include_query_string']))->unique()->values()->all() : null;
        $purge = DB::transaction(function () use ($domain, $data, $keys, $request): CachePurge {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            if ($data['type'] === 'all') {
                $locked->increment('cache_epoch');
            }
            $purge = CachePurge::query()->create(['domain_id' => $locked->id, 'requested_by' => $request->user()->id, 'type' => $data['type'], 'cache_epoch' => $locked->cache_epoch, 'cache_keys' => $keys, 'status' => 'pending']);
            foreach (Edge::query()->where('enabled', true)->whereNull('identity_revoked_at')->cursor() as $edge) {
                EdgeTask::query()->create(['id' => (string) Str::uuid(), 'edge_id' => $edge->id, 'cache_purge_id' => $purge->id, 'type' => 'cache_purge', 'payload' => ['domain_id' => $locked->id, 'domain' => $locked->name, 'type' => $data['type'], 'cache_epoch' => $locked->cache_epoch, 'cache_keys' => $keys], 'status' => 'pending']);
            }
            $purge->update(['status' => $purge->tasks()->exists() ? 'running' : 'succeeded']);
            AuditLog::record($request->user(), 'cache.purge_requested', $purge, ['type' => $data['type'], 'url_count' => count($keys ?? [])], $request->ip());

            return $purge;
        });
        if ($data['type'] === 'all') {
            ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();
        }

        return response()->json(['data' => $this->purgeData($purge)], 202);
    }

    public function purges(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => CachePurge::query()->where('domain_id', $domain->id)->latest('created_at')->cursorPaginate(50)]);
    }

    public function purgeStatus(Domain $domain, CachePurge $purge): JsonResponse
    {
        Gate::authorize('view', $domain);
        abort_unless($purge->domain_id === $domain->id, 404);

        return response()->json(['data' => $this->purgeData($purge)]);
    }

    public static function defaults(): array
    {
        return ['enabled' => true, 'edge_ttl_seconds' => 3600, 'browser_ttl_seconds' => 300, 'maximum_object_bytes' => 104857600, 'respect_origin_headers' => true, 'include_query_string' => true, 'bypass_cookie_names' => [], 'stale_if_error_seconds' => 60];
    }

    private function state(Domain $domain): array
    {
        return ['settings' => $domain->cache_settings ?? self::defaults(), 'cache_epoch' => $domain->cache_epoch, 'development_mode_until' => $domain->cache_development_mode_until?->isFuture() ? $domain->cache_development_mode_until->toIso8601String() : null];
    }

    private function purgeData(CachePurge $purge): array
    {
        $tasks = $purge->tasks()->get(['edge_id', 'status', 'result', 'finished_at']);

        return [...$purge->only(['id', 'domain_id', 'type', 'cache_epoch', 'status', 'created_at']), 'url_count' => count($purge->cache_keys ?? []), 'edges' => $tasks];
    }

    private function rules(): array
    {
        return ['enabled' => ['required', 'boolean'], 'edge_ttl_seconds' => ['required', 'integer', 'between:0,31536000'], 'browser_ttl_seconds' => ['required', 'integer', 'between:0,31536000'], 'maximum_object_bytes' => ['required', 'integer', 'between:1024,1073741824'], 'respect_origin_headers' => ['required', 'boolean'], 'include_query_string' => ['required', 'boolean'], 'bypass_cookie_names' => ['required', 'array', 'max:32'], 'bypass_cookie_names.*' => ['required', 'regex:/^[A-Za-z0-9_\-]{1,64}$/', 'distinct'], 'stale_if_error_seconds' => ['required', 'integer', 'between:0,86400']];
    }
}
