<?php

return [
    'artifact_signing_key' => env('EDGE_ARTIFACT_SIGNING_KEY', env('APP_KEY')),
    'identity_ca_certificate' => env('EDGE_IDENTITY_CA_CERTIFICATE', '/run/secrets/edge-identity-ca.crt'),
    'identity_ca_private_key' => env('EDGE_IDENTITY_CA_PRIVATE_KEY', '/run/secrets/edge-identity-ca.key'),
    'identity_ca_private_key_passphrase' => env('EDGE_IDENTITY_CA_PRIVATE_KEY_PASSPHRASE', ''),
];
