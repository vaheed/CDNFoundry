<?php

namespace App\Http\Controllers;

use App\Actions\DispatchCachePurge;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\CachePurge;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
        $purge = DispatchCachePurge::execute($domain, $data['type'], $data['urls'] ?? [], $request->user(), $request->ip());

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
        return ['enabled' => ['required', 'boolean'], 'edge_ttl_seconds' => ['required', 'integer', 'between:0,31536000'], 'browser_ttl_seconds' => ['required', 'integer', 'between:0,31536000'], 'maximum_object_bytes' => ['required', 'integer', 'in:1048576,10485760,104857600'], 'respect_origin_headers' => ['required', 'boolean'], 'include_query_string' => ['required', 'boolean'], 'bypass_cookie_names' => ['required', 'array', 'max:32'], 'bypass_cookie_names.*' => ['required', 'regex:/^[A-Za-z0-9_\-]{1,64}$/', 'distinct'], 'stale_if_error_seconds' => ['required', 'integer', 'between:0,86400']];
    }
}
