<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ControlFingerprints extends Command
{
    protected $signature = 'cdnf:control:fingerprints';

    protected $description = 'Print a fingerprint of each shared control secret as JSON (never the value)';

    public function handle(): int
    {
        $files = [
            'edge_identity_ca_certificate' => config('edge.identity_ca_certificate'),
            'edge_identity_ca_private_key' => config('edge.identity_ca_private_key'),
        ];
        $values = [
            'app_key' => (string) config('app.key'),
            'artifact_signing_key' => (string) config('edge.artifact_signing_key'),
            'edge_proxy_secret' => (string) config('edge.proxy_secret'),
        ];
        foreach ($files as $name => $path) {
            $values[$name] = is_string($path) && is_readable($path) ? (string) file_get_contents($path) : '';
        }
        ksort($values);
        $fingerprints = array_map(fn (string $value): string => $value === '' ? 'missing' : substr(hash_hmac('sha256', $value, 'cdnf-control-fingerprint'), 0, 16), $values);
        $this->line(json_encode($fingerprints, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
