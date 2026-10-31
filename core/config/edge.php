<?php

return [
    'artifact_signing_key' => env('EDGE_ARTIFACT_SIGNING_KEY', env('APP_KEY')),
    // Set while rotating: the next signing seed, advertised to agents (endorsed by the current key)
    // before it becomes EDGE_ARTIFACT_SIGNING_KEY. Empty outside a rotation.
    'artifact_signing_next_key' => env('EDGE_ARTIFACT_SIGNING_NEXT_KEY'),
    'identity_ca_certificate' => env('EDGE_IDENTITY_CA_CERTIFICATE', '/run/secrets/edge-identity-ca.crt'),
    'identity_ca_private_key' => env('EDGE_IDENTITY_CA_PRIVATE_KEY', '/run/secrets/edge-identity-ca.key'),
    'identity_ca_private_key_passphrase' => env('EDGE_IDENTITY_CA_PRIVATE_KEY_PASSPHRASE', ''),
    // Edge identity certificates are renewed over mTLS at two thirds of this lifetime.
    'identity_validity_days' => max(2, min(3650, (int) env('EDGE_IDENTITY_VALIDITY_DAYS', 365))),
];
