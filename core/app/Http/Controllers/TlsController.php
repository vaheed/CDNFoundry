<?php

namespace App\Http\Controllers;

use App\Jobs\EnsureManagedCertificates;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\TlsCertificate;
use App\Models\TlsOrder;
use App\Support\UploadedCertificate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TlsController extends Controller
{
    public function show(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => $this->state($domain)]);
    }

    public function status(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);
        $latestOrder = TlsOrder::query()->where('domain_id', $domain->id)->latest()->first();

        return response()->json(['data' => [
            ...$this->state($domain),
            'latest_order' => $latestOrder?->only([
                'id', 'status', 'names', 'attempts', 'available_at', 'started_at', 'finished_at', 'last_error', 'created_at', 'updated_at',
            ]),
        ]]);
    }

    public function update(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $data = $request->validate(['mode' => ['required', 'in:managed,custom,disabled']]);
        if ($data['mode'] === 'custom') {
            abort_unless($domain->tlsCertificates()->where('kind', 'custom')->where('status', 'active')->where('expires_at', '>', now())->exists(), 409, 'Upload a valid custom certificate before selecting custom mode.');
        }
        DB::transaction(function () use ($domain, $data, $request): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $certificateId = $data['mode'] === 'custom'
                ? $locked->tlsCertificates()->where('kind', 'custom')->where('status', 'active')->where('expires_at', '>', now())->latest('activated_at')->value('id')
                : ($data['mode'] === 'managed' ? $locked->tlsCertificates()->where('kind', 'managed')->where('status', 'active')->where('expires_at', '>', now())->latest('activated_at')->value('id') : $locked->active_tls_certificate_id);
            $locked->update(['tls_mode' => $data['mode'], 'active_tls_certificate_id' => $certificateId, 'revision' => $locked->revision + 1]);
            AuditLog::record($request->user(), 'tls.mode_updated', $locked, ['mode' => $data['mode'], 'revision' => $locked->revision], $request->ip());
        });

        if ($data['mode'] === 'managed') {
            EnsureManagedCertificates::dispatch($domain->id)->afterCommit();
        }

        return $this->queue($request, $domain);
    }

    public function upload(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $data = $request->validate([
            'certificate' => ['required', 'string', 'max:16384'], 'chain' => ['required', 'string', 'max:65536'],
            'private_key' => ['required', 'string', 'max:16384'],
        ]);
        $validated = UploadedCertificate::validate($domain, $data['certificate'], $data['chain'], $data['private_key']);
        $certificate = DB::transaction(function () use ($domain, $validated, $request): TlsCertificate {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $existing = $locked->tlsCertificates()->where('kind', 'custom')->where('fingerprint_sha256', $validated['fingerprint_sha256'])->first();
            if ($existing !== null && $existing->expires_at->isFuture()) {
                $certificate = $existing;
                $certificate->update(['status' => 'active', 'activated_at' => $certificate->activated_at ?? now(), 'last_error' => null]);
            } else {
                $certificate = $locked->tlsCertificates()->create([
                    'kind' => 'custom', 'status' => 'active', 'certificate_pem' => $validated['certificate_pem'],
                    'chain_pem' => $validated['chain_pem'], 'private_key_ciphertext' => $validated['private_key'],
                    'names' => $validated['names'], 'fingerprint_sha256' => $validated['fingerprint_sha256'],
                    'not_before' => $validated['not_before'], 'expires_at' => $validated['expires_at'], 'activated_at' => now(),
                ]);
            }
            $locked->tlsCertificates()->where('kind', 'custom')->where('id', '!=', $certificate->id)->where('status', 'active')->update(['status' => 'superseded']);
            $locked->update(['tls_mode' => 'custom', 'active_tls_certificate_id' => $certificate->id, 'revision' => $locked->revision + 1]);
            AuditLog::record($request->user(), 'tls.custom_uploaded', $certificate, ['domain_id' => $locked->id, 'revision' => $locked->revision, 'fingerprint' => $certificate->fingerprint_sha256], $request->ip());

            return $certificate;
        });
        $response = $this->queue($request, $domain);

        return $response->setData(['data' => ['certificate' => $this->certificateData($certificate), 'operation_id' => $response->getData(true)['data']['operation_id'], 'status' => 'pending']]);
    }

    public function reissue(Request $request, Domain $domain): JsonResponse
    {
        return $this->queueManaged($request, $domain, true);
    }

    public function renew(Request $request, Domain $domain): JsonResponse
    {
        return $this->queueManaged($request, $domain, false);
    }

    public function destroyCustom(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        DB::transaction(function () use ($domain, $request): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $custom = $locked->tlsCertificates()->where('kind', 'custom')->where('status', 'active')->get();
            $custom->each->update(['status' => 'revoked']);
            $managed = $locked->tlsCertificates()->where('kind', 'managed')->where('status', 'active')->where('expires_at', '>', now())->latest('activated_at')->first();
            $locked->update(['tls_mode' => 'managed', 'active_tls_certificate_id' => $managed?->id, 'revision' => $locked->revision + 1]);
            AuditLog::record($request->user(), 'tls.custom_deleted', $locked, ['revision' => $locked->revision], $request->ip());
        });
        EnsureManagedCertificates::dispatch($domain->id)->afterCommit();

        return $this->queue($request, $domain);
    }

    private function queue(Request $request, Domain $domain): JsonResponse
    {
        $operation = Operation::coalesceDomain('edge.domain_reconcile', $domain->id, $request->user()->id);
        ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => $operation->status]], 202);
    }

    private function queueManaged(Request $request, Domain $domain, bool $force): JsonResponse
    {
        Gate::authorize('update', $domain);
        abort_unless($domain->lifecycle_state->value === 'active' && $domain->nameservers_verified_at !== null, 409, 'Managed TLS requires an active, nameserver-verified domain.');
        abort_unless($domain->dnsRecords()->where('mode', 'proxied')->exists(), 409, 'Managed TLS requires at least one proxied hostname.');
        $operation = Operation::query()->create([
            'actor_id' => $request->user()->id, 'type' => $force ? 'tls.managed_reissue' : 'tls.managed_renew',
            'status' => 'pending', 'input' => ['domain_id' => $domain->id],
        ]);
        AuditLog::record($request->user(), $force ? 'tls.reissue_queued' : 'tls.renewal_queued', $domain, ['operation_id' => $operation->id], $request->ip());
        EnsureManagedCertificates::dispatch($domain->id, $force)->afterCommit();

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => 'pending']], 202);
    }

    private function state(Domain $domain): array
    {
        $certificate = $domain->activeTlsCertificate()->first();

        return [
            'mode' => $domain->tls_mode,
            'active_certificate' => $certificate === null ? null : $this->certificateData($certificate),
            'certificates' => $domain->tlsCertificates()->orderByDesc('activated_at')->limit(100)->get()->map(fn (TlsCertificate $item): array => $this->certificateData($item)),
        ];
    }

    private function certificateData(TlsCertificate $certificate): array
    {
        return $certificate->only(['id', 'kind', 'status', 'names', 'fingerprint_sha256', 'not_before', 'expires_at', 'activated_at', 'last_error']);
    }
}
