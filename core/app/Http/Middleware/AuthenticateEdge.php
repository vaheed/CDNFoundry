<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\Edge;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateEdge
{
    public function handle(Request $request, Closure $next): Response
    {
        $verified = $request->header('X-Edge-Certificate-Verify');
        $serial = strtoupper((string) $request->header('X-Edge-Certificate-Serial'));
        // A renewed certificate is accepted beside the current one until the
        // edge authenticates with it, which proves it holds the new key.
        $edge = $verified === 'SUCCESS' && $serial !== ''
            ? Edge::query()->whereNull('identity_revoked_at')->where(fn ($query) => $query
                ->where(fn ($current) => $current->where('identity_certificate_serial', $serial)->where('identity_certificate_expires_at', '>', now()))
                ->orWhere(fn ($pending) => $pending->where('pending_identity_certificate_serial', $serial)->where('pending_identity_certificate_expires_at', '>', now())))->first()
            : null;
        abort_if($edge === null, 401, 'A valid edge identity is required.');
        $encodedCertificate = (string) $request->header('X-Edge-Certificate-Pem');
        abort_if($encodedCertificate === '' || strlen($encodedCertificate) > 16384, 401, 'The enrolled edge certificate is required.');
        $usingPending = $edge->pending_identity_certificate_serial === $serial;
        $presented = @openssl_x509_fingerprint(rawurldecode($encodedCertificate), 'sha256');
        $enrolled = @openssl_x509_fingerprint((string) ($usingPending ? $edge->pending_identity_certificate : $edge->identity_certificate), 'sha256');
        abort_if($presented === false || $enrolled === false || ! hash_equals($enrolled, $presented), 401, 'The presented certificate is not the enrolled edge identity.');
        if ($usingPending) {
            $edge->forceFill([
                'identity_certificate' => $edge->pending_identity_certificate,
                'identity_certificate_serial' => $edge->pending_identity_certificate_serial,
                'identity_certificate_expires_at' => $edge->pending_identity_certificate_expires_at,
                'identity_csr_hash' => $edge->pending_identity_csr_hash,
                'pending_identity_certificate' => null, 'pending_identity_certificate_serial' => null,
                'pending_identity_certificate_expires_at' => null, 'pending_identity_csr_hash' => null,
            ])->save();
            AuditLog::record(null, 'edge.identity_renewed', $edge, ['serial' => $serial]);
        }
        $request->attributes->set('edge', $edge);

        return $next($request);
    }
}
