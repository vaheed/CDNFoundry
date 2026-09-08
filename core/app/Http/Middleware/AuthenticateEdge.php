<?php

namespace App\Http\Middleware;

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
        $edge = $verified === 'SUCCESS' && $serial !== ''
            ? Edge::query()->where('identity_certificate_serial', $serial)->where('identity_certificate_expires_at', '>', now())->whereNull('identity_revoked_at')->first()
            : null;
        abort_if($edge === null, 401, 'A valid edge identity is required.');
        $encodedCertificate = (string) $request->header('X-Edge-Certificate-Pem');
        abort_if($encodedCertificate === '' || strlen($encodedCertificate) > 16384, 401, 'The enrolled edge certificate is required.');
        $presented = @openssl_x509_fingerprint(rawurldecode($encodedCertificate), 'sha256');
        $enrolled = @openssl_x509_fingerprint((string) $edge->identity_certificate, 'sha256');
        abort_if($presented === false || $enrolled === false || ! hash_equals($enrolled, $presented), 401, 'The presented certificate is not the enrolled edge identity.');
        $request->attributes->set('edge', $edge);

        return $next($request);
    }
}
