<?php

namespace App\Http\Controllers;

use App\Http\Resources\LogPushDestinationResource;
use App\Jobs\TestLogPushDestination;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\LogPushDestination;
use App\Models\Operation;
use App\Support\LogPush\DestinationData;
use App\Support\PlatformSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Log push destinations of one domain, for the people who may see that domain. Adding one needs the administrator switch. */
class LogPushController extends Controller
{
    public function index(Domain $domain): AnonymousResourceCollection
    {
        Gate::authorize('view', $domain);

        return LogPushDestinationResource::collection($domain->logPushDestinations()->orderBy('created_at')->orderBy('id')->cursorPaginate(50));
    }

    public function store(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $this->requireSwitch();
        $data = DestinationData::validate($request->all());
        $destination = DB::transaction(function () use ($request, $domain, $data): LogPushDestination {
            // Serialize creators of one domain so the per-domain and platform bounds hold.
            Domain::query()->lockForUpdate()->findOrFail($domain->id);
            DestinationData::assertRoom($domain);
            $destination = new LogPushDestination([
                'domain_id' => $domain->id, 'kind' => $data['kind'], 'name' => $data['name'], 'datasets' => $data['datasets'], 'destination_hint' => $data['hint'],
                'truncate_client_ip' => $data['truncate'], 'status' => 'active', 'cursors' => [], 'consecutive_failures' => 0, 'failures_total' => 0, 'rows_pushed' => 0, 'bytes_pushed' => 0,
            ]);
            $destination->storeSecrets($data['secrets']);
            $destination->save();
            AuditLog::record($request->user(), 'log_push.created', $domain, ['destination_id' => $destination->id, 'kind' => $destination->kind, 'datasets' => $destination->datasets], $request->ip());

            return $destination;
        });

        // The signing secret of an HMAC destination is shown here once and never again.
        return LogPushDestinationResource::make($destination)->additional(['meta' => array_filter(['signing_secret' => $data['signing_secret']])])->response()->setStatusCode(201);
    }

    public function update(Request $request, Domain $domain, string $destination): LogPushDestinationResource
    {
        Gate::authorize('update', $domain);
        $found = $domain->logPushDestinations()->findOrFail($destination);
        $validated = $request->validate(['status' => ['required', Rule::in(['active', 'paused'])]]);
        $found->forceFill(['status' => $validated['status'], 'next_run_at' => null])->save();
        AuditLog::record($request->user(), 'log_push.'.($validated['status'] === 'paused' ? 'paused' : 'resumed'), $domain, ['destination_id' => $found->id], $request->ip());

        return LogPushDestinationResource::make($found);
    }

    public function destroy(Request $request, Domain $domain, string $destination): JsonResponse
    {
        Gate::authorize('update', $domain);
        $found = $domain->logPushDestinations()->findOrFail($destination);
        $found->delete();
        AuditLog::record($request->user(), 'log_push.deleted', $domain, ['destination_id' => $found->id], $request->ip());

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function test(Request $request, Domain $domain, string $destination): JsonResponse
    {
        Gate::authorize('update', $domain);
        $this->requireSwitch();
        $found = $domain->logPushDestinations()->findOrFail($destination);
        try {
            $operation = Operation::query()->create(['actor_id' => $request->user()->getKey(), 'domain_id' => $domain->id, 'type' => 'log_push.test', 'status' => 'pending', 'input' => ['domain_id' => $domain->id, 'destination_id' => $found->id]]);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'A test for this domain is already running.');
        }
        TestLogPushDestination::dispatch($operation->id);

        return response()->json(['data' => ['operation_id' => $operation->id]], 202);
    }

    private function requireSwitch(): void
    {
        abort_unless(app(PlatformSettings::class)->get('log_push', 'domain_user_log_push') === true, 403, 'Log push is turned off by the administrator.');
    }
}
