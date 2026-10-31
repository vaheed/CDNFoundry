<?php

return [
    'artifact_signing_key' => env('EDGE_ARTIFACT_SIGNING_KEY', env('APP_KEY')),
    // Set while rotating: the next signing seed, advertised to agents (endorsed by the current key)
    // before it becomes EDGE_ARTIFACT_SIGNING_KEY. Empty outside a rotation.
    'artifact_signing_next_key' => env('EDGE_ARTIFACT_SIGNING_NEXT_KEY'),
    // Shared by edge-control's nginx and PHP: the edge identity headers are trusted only with it.
    // The file is the nginx include itself (`fastcgi_param HTTP_X_EDGE_PROXY_SECRET "<secret>";`).
    'proxy_secret' => env('EDGE_PROXY_SECRET') ?: (is_file((string) env('EDGE_PROXY_SECRET_FILE'))
        && preg_match('/"([A-Za-z0-9]{32,})"/', (string) file_get_contents((string) env('EDGE_PROXY_SECRET_FILE')), $matches) === 1 ? $matches[1] : ''),
    'identity_ca_certificate' => env('EDGE_IDENTITY_CA_CERTIFICATE', '/run/secrets/edge-identity-ca.crt'),
    'identity_ca_private_key' => env('EDGE_IDENTITY_CA_PRIVATE_KEY', '/run/secrets/edge-identity-ca.key'),
    'identity_ca_private_key_passphrase' => env('EDGE_IDENTITY_CA_PRIVATE_KEY_PASSPHRASE', ''),
    // Edge identity certificates are renewed over mTLS at two thirds of this lifetime.
    'identity_validity_days' => max(2, min(3650, (int) env('EDGE_IDENTITY_VALIDITY_DAYS', 365))),
];
