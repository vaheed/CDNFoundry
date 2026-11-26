<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Support\JwtValidationData;

/** JWT validation at the edge: public keys, paths, issuer and audience. Each save is one signed revision and a queued edge update. */
class JwtValidationController extends DetectionSettingsController
{
    protected function column(): string
    {
        return 'jwt_validation';
    }

    protected function auditName(): string
    {
        return 'security.jwt_validation';
    }

    protected function validated(array $input): array
    {
        return JwtValidationData::validate($input);
    }

    protected function payload(Domain $domain): array
    {
        $settings = $domain->jwt_validation;

        return [
            'jwt_validation' => is_array($settings) ? [...$settings, 'keys' => array_map(fn (array $key): array => [
                'kid' => $key['kid'], 'alg' => $key['alg'], 'expires_at' => $key['expires_at'], 'fingerprint' => hash('sha256', $key['pem']),
            ], $settings['keys'])] : null,
            'maximum_keys' => JwtValidationData::MAXIMUM_KEYS, 'maximum_paths' => JwtValidationData::MAXIMUM_PATHS, 'algorithms' => array_keys(JwtValidationData::ALGORITHMS),
        ];
    }
}
