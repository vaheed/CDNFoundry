<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Support\DomainSecurityRules;
use App\Support\SecurityRuleData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** One detection's settings on a domain: shown, replaced, or removed, each save one signed revision and a queued edge update. */
abstract class DetectionSettingsController extends Controller
{
    /** The domains column that holds the settings. */
    abstract protected function column(): string;

    /** The audit action prefix, such as "security.credential_check". */
    abstract protected function auditName(): string;

    abstract protected function validated(array $input): array;

    abstract protected function payload(Domain $domain): array;

    public function show(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => $this->payload($domain)]);
    }

    public function update(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);

        return $this->save($request, $domain, $this->validated($request->all()), '_updated');
    }

    public function destroy(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        if (isset(SecurityRuleData::REQUIREMENT_LABELS[$this->column()])) {
            SecurityRuleData::assertDetectionUnused($domain, $this->column());
        }

        return $this->save($request, $domain, null, '_removed');
    }

    private function save(Request $request, Domain $domain, ?array $settings, string $suffix): JsonResponse
    {
        DB::transaction(function () use ($domain, $request, $settings, $suffix): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $locked->update([$this->column() => $settings, 'revision' => $locked->revision + 1]);
            AuditLog::record($request->user(), $this->auditName().$suffix, $locked, ['revision' => $locked->revision], $request->ip());
        });
        $operation = DomainSecurityRules::queue($domain, $request->user());

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => $operation->status]], 202);
    }
}
