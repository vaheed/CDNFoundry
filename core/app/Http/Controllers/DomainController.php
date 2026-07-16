<?php

namespace App\Http\Controllers;

use App\Enums\DomainLifecycleState;
use App\Http\Requests\StoreDomainRequest;
use App\Http\Resources\DomainResource;
use App\Models\AuditLog;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DomainController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Domain::query()->orderBy('id');
        if (! $request->user()->isAdmin()) {
            $query->whereHas('users', fn ($users) => $users->whereKey($request->user()->getKey()));
        }

        return DomainResource::collection($query->cursorPaginate(50));
    }

    public function store(StoreDomainRequest $request): JsonResponse
    {
        $domain = DB::transaction(function () use ($request): Domain {
            $domain = Domain::query()->create([
                'name' => $request->validated('name'),
                'display_name' => trim((string) $request->input('name')),
                'lifecycle_state' => DomainLifecycleState::PendingVerification,
                'revision' => 1,
            ]);
            if (! $request->user()->isAdmin()) {
                $domain->users()->attach($request->user()->getKey());
            }
            AuditLog::record($request->user(), 'domain.created', $domain, ['name' => $domain->name], $request->ip());

            return $domain;
        });

        return DomainResource::make($domain)->response()->setStatusCode(201);
    }

    public function show(Request $request, Domain $domain): DomainResource
    {
        Gate::authorize('view', $domain);

        return DomainResource::make($domain);
    }

    public function disable(Request $request, Domain $domain): DomainResource
    {
        Gate::authorize('update', $domain);
        if ($domain->lifecycle_state !== DomainLifecycleState::Deprovisioning && $domain->lifecycle_state !== DomainLifecycleState::Disabled) {
            $domain->forceFill(['lifecycle_state' => DomainLifecycleState::Disabled, 'disabled_at' => now(), 'revision' => $domain->revision + 1])->save();
            AuditLog::record($request->user(), 'domain.disabled', $domain, ['revision' => $domain->revision], $request->ip());
        }

        return DomainResource::make($domain->refresh());
    }

    public function destroy(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('delete', $domain);
        if ($domain->lifecycle_state !== DomainLifecycleState::Deprovisioning) {
            $domain->forceFill([
                'lifecycle_state' => DomainLifecycleState::Deprovisioning,
                'deprovision_after' => now()->addDays(7),
                'revision' => $domain->revision + 1,
            ])->save();
            AuditLog::record($request->user(), 'domain.deprovisioning_started', $domain, ['revision' => $domain->revision], $request->ip());
        }

        return response()->json(['data' => DomainResource::make($domain->refresh())], 202);
    }
}
