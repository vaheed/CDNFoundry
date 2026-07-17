<?php

namespace App\Http\Controllers;

use App\Enums\DomainLifecycleState;
use App\Jobs\ImportDnsZone;
use App\Jobs\ReconcileDnsZone;
use App\Models\DnsCluster;
use App\Models\Domain;
use App\Models\Operation;
use App\Support\BindZone;
use App\Support\DnsZoneImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class DnsZoneController extends Controller
{
    private const ASYNC_BYTES = 65536;

    private const ASYNC_LINES = 100;

    public function import(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $validated = $request->validate([
            'zone' => ['required', 'string', 'max:'.BindZone::MAX_BYTES],
            'replace_existing' => ['sometimes', 'boolean'],
        ]);
        if (strlen($validated['zone']) > BindZone::MAX_BYTES) {
            throw ValidationException::withMessages(['zone' => 'Zone input exceeds the 1 MiB limit.']);
        }
        $replace = (bool) ($validated['replace_existing'] ?? false);
        if (strlen($validated['zone']) > self::ASYNC_BYTES || substr_count($validated['zone'], "\n") + 1 > self::ASYNC_LINES) {
            $operation = Operation::query()->create([
                'actor_id' => $request->user()->getKey(), 'type' => 'dns.zone_import', 'status' => 'pending',
                'input' => ['domain_id' => $domain->id, 'zone' => $validated['zone'], 'replace_existing' => $replace, 'ip_address' => $request->ip()],
            ]);
            ImportDnsZone::dispatch($operation->getKey())->afterCommit();

            return response()->json(['data' => $operation], 202);
        }

        try {
            $records = BindZone::parse($validated['zone'], $domain->name);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['zone' => $exception->getMessage()]);
        }
        $result = DnsZoneImporter::apply($domain->id, $records, $replace, $request->user(), $request->ip());
        if ($domain->refresh()->lifecycle_state === DomainLifecycleState::Active && DnsCluster::query()->where('enabled', true)->exists()) {
            ReconcileDnsZone::dispatch($domain->id)->afterCommit();
        }

        return response()->json(['data' => $result]);
    }

    public function export(Domain $domain): Response
    {
        Gate::authorize('view', $domain);

        return response(BindZone::export($domain), 200, [
            'Content-Type' => 'text/dns; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$domain->name.'.zone"',
        ]);
    }
}
