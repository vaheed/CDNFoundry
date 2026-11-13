<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Support\CredentialCheckData;
use App\Support\DomainSecurityRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CredentialCheckController extends Controller
{
    public function show(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => $this->payload($domain)]);
    }

    public function update(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $check = CredentialCheckData::validate($request->all());

        return $this->save($request, $domain, $check, 'security.credential_check_updated');
    }

    public function destroy(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);

        return $this->save($request, $domain, null, 'security.credential_check_removed');
    }

    private function save(Request $request, Domain $domain, ?array $check, string $action): JsonResponse
    {
        DB::transaction(function () use ($action, $check, $domain, $request): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $locked->update(['credential_check' => $check, 'revision' => $locked->revision + 1]);
            AuditLog::record($request->user(), $action, $locked, ['login_paths' => count($check['login_paths'] ?? []), 'revision' => $locked->revision], $request->ip());
        });
        $operation = DomainSecurityRules::queue($domain, $request->user());

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => $operation->status]], 202);
    }

    private function payload(Domain $domain): array
    {
        return ['credential_check' => $domain->credential_check, 'formats' => array_keys(CredentialCheckData::FORMATS), 'maximum_login_paths' => CredentialCheckData::MAXIMUM_LOGIN_PATHS];
    }
}
