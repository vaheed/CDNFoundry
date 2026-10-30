<?php

return [
    // Optional PEM bundle that replaces the system trust anchors when validating uploaded
    // certificate chains. Unset, the operating system store is used. Set it for a private PKI.
    'upload_trust_file' => env('TLS_UPLOAD_TRUST_FILE'),
];
