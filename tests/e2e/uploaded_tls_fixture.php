<?php

use App\Support\UploadedCertificate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Tests\Support\CertificateChain;

// Synthetic private material is emitted only to the owning Python process.
// It must never be copied into qualification logs.
require '/app/vendor/autoload.php';
require '/fixtures/UploadedCertificate.php';
require '/fixtures/CertificateChain.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
    throw new RuntimeException('Synthetic TLS fixture requires isolated testing configuration.');
}
// No database is connected or migrated. API transactions are covered by TlsApiTest.
$validator = new ReflectionMethod(UploadedCertificate::class, 'validateChain');
$cases = [];
foreach (['valid', 'issuer_not_ca', 'issuer_key_usage', 'expired_issuer', 'expired_root', 'path_length', 'server_purpose', 'name_constraint', 'critical_extension',
    'weak_issuer_key', 'weak_root_key', 'weak_leaf_signature', 'weak_issuer_signature'] as $name) {
    $bundle = CertificateChain::make($name);
    try {
        $validator->invoke(null, openssl_x509_read($bundle['certificate']), $bundle['chain']);
        $accepted = true;
    } catch (ValidationException) {
        $accepted = false;
    }
    $cases[$name] = ['accepted' => $accepted, 'bundle' => $bundle];
}
echo json_encode(['openssl' => OPENSSL_VERSION_TEXT, 'openssl_cli' => trim(Process::run(['openssl', 'version'])->throw()->output()), 'cases' => $cases], JSON_THROW_ON_ERROR);
