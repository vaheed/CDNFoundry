<?php

namespace App\Support;

use App\Models\AcmeAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class AcmeClient
{
    /** @var array<string, string>|null */
    private ?array $directory = null;

    public function account(): AcmeAccount
    {
        $directoryUrl = (string) config('services.acme.directory_url');
        $email = trim((string) config('services.acme.contact_email'));
        if (! config('services.acme.enabled') || $email === '') {
            throw new RuntimeException('Managed TLS is unavailable until ACME is enabled with a contact email.');
        }

        $account = AcmeAccount::query()->firstOrCreate(['directory_url' => $directoryUrl], [
            'contact_email' => $email,
            'private_key_ciphertext' => $this->newPrivateKey(),
        ]);
        if ($account->contact_email !== $email) {
            $account->update(['contact_email' => $email]);
        }
        if ($account->account_url === null) {
            $response = $this->signedRequest($this->directory()['newAccount'], [
                'termsOfServiceAgreed' => true,
                'contact' => ['mailto:'.$email],
            ], $account, true);
            $location = $response->header('Location');
            if (! is_string($location) || $location === '') {
                throw new RuntimeException('The ACME server did not return an account URL.');
            }
            $account->update(['account_url' => $location]);
        }

        return $account->refresh();
    }

    /** @param list<string> $names */
    public function createOrder(AcmeAccount $account, array $names): array
    {
        $response = $this->signedRequest($this->directory()['newOrder'], [
            'identifiers' => array_map(fn (string $name): array => ['type' => 'dns', 'value' => $name], $names),
        ], $account);
        $body = $response->json();
        foreach (['authorizations', 'finalize'] as $required) {
            if (! is_array($body) || ! isset($body[$required])) {
                throw new RuntimeException("The ACME order response omitted {$required}.");
            }
        }

        return [
            'order_url' => (string) $response->header('Location'),
            'authorization_urls' => array_values($body['authorizations']),
            'finalize_url' => (string) $body['finalize'],
        ];
    }

    public function dnsChallenge(AcmeAccount $account, string $authorizationUrl): array
    {
        $authorization = $this->postAsGet($authorizationUrl, $account)->json();
        $identifier = $authorization['identifier']['value'] ?? null;
        if (($authorization['status'] ?? null) === 'valid' && is_string($identifier)) {
            return ['already_valid' => true, 'hostname' => $identifier, 'authorization_url' => $authorizationUrl];
        }
        $challenge = collect($authorization['challenges'] ?? [])->firstWhere('type', 'dns-01');
        if (! is_string($identifier) || ! is_array($challenge) || ! is_string($challenge['token'] ?? null) || ! is_string($challenge['url'] ?? null)) {
            throw new RuntimeException('The ACME authorization did not contain a usable DNS-01 challenge.');
        }
        $thumbprint = $this->thumbprint($account);
        $value = $this->base64Url(hash('sha256', $challenge['token'].'.'.$thumbprint, true));
        $base = ltrim($identifier, '*.');

        return [
            'already_valid' => false,
            'hostname' => $identifier,
            'record_name' => '_acme-challenge.'.$base,
            'record_value' => $value,
            'authorization_url' => $authorizationUrl,
            'challenge_url' => $challenge['url'],
            'token' => $challenge['token'],
        ];
    }

    public function acknowledgeChallenge(AcmeAccount $account, string $challengeUrl): void
    {
        $this->signedRequest($challengeUrl, new \stdClass, $account);
    }

    public function authorizationStatus(AcmeAccount $account, string $url): array
    {
        $body = $this->postAsGet($url, $account)->json();

        return ['status' => (string) ($body['status'] ?? 'unknown'), 'error' => $body['error']['detail'] ?? null];
    }

    /** @param list<string> $names */
    public function finalizeOrder(AcmeAccount $account, string $url, array $names): array
    {
        [$privateKey, $csr] = $this->certificateRequest($names);
        $this->signedRequest($url, ['csr' => $this->base64Url($csr)], $account);

        return ['private_key' => $privateKey, 'csr_der' => base64_encode($csr)];
    }

    public function orderStatus(AcmeAccount $account, string $url): array
    {
        $body = $this->postAsGet($url, $account)->json();

        return [
            'status' => (string) ($body['status'] ?? 'unknown'),
            'certificate_url' => isset($body['certificate']) ? (string) $body['certificate'] : null,
            'error' => $body['error']['detail'] ?? null,
        ];
    }

    public function downloadCertificate(AcmeAccount $account, string $url): array
    {
        $pem = $this->postAsGet($url, $account)->body();
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----\s*/s', $pem, $matches);
        if (count($matches[0]) < 1) {
            throw new RuntimeException('The ACME certificate response was not a PEM certificate chain.');
        }

        return ['certificate_pem' => trim($matches[0][0])."\n", 'chain_pem' => implode('', array_slice($matches[0], 1))];
    }

    private function postAsGet(string $url, AcmeAccount $account): Response
    {
        return $this->signedRequest($url, null, $account);
    }

    private function signedRequest(string $url, mixed $payload, AcmeAccount $account, bool $useJwk = false, bool $retryNonce = true): Response
    {
        $protected = ['alg' => 'ES256', 'nonce' => $this->nonce(), 'url' => $url];
        $protected[$useJwk ? 'jwk' : 'kid'] = $useJwk ? $this->jwk($account) : $account->account_url;
        $protected64 = $this->base64Url(json_encode($protected, JSON_THROW_ON_ERROR));
        $payload64 = $payload === null ? '' : $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = '';
        if (! openssl_sign($protected64.'.'.$payload64, $signature, $account->private_key_ciphertext, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the ACME request.');
        }
        $body = json_encode([
            'protected' => $protected64, 'payload' => $payload64, 'signature' => $this->base64Url($this->ecdsaDerToRaw($signature)),
        ], JSON_THROW_ON_ERROR);
        $response = $this->http()->withBody($body, 'application/jose+json')->accept('application/json')->timeout(20)->connectTimeout(5)->post($url);
        if ($response->successful()) {
            return $response;
        }
        if ($retryNonce && $response->status() === 400 && $response->json('type') === 'urn:ietf:params:acme:error:badNonce') {
            return $this->signedRequest($url, $payload, $account, $useJwk, false);
        }
        $detail = $response->json('detail') ?: "HTTP {$response->status()}";
        throw new RuntimeException('ACME request failed: '.mb_substr((string) $detail, 0, 1000));
    }

    /** @return array<string, string> */
    private function directory(): array
    {
        if ($this->directory === null) {
            $body = $this->http()->acceptJson()->timeout(15)->connectTimeout(5)->get((string) config('services.acme.directory_url'))->throw()->json();
            foreach (['newNonce', 'newAccount', 'newOrder'] as $key) {
                if (! is_string($body[$key] ?? null)) {
                    throw new RuntimeException("The ACME directory omitted {$key}.");
                }
            }
            $this->directory = $body;
        }

        return $this->directory;
    }

    private function nonce(): string
    {
        $response = $this->http()->timeout(10)->connectTimeout(5)->head($this->directory()['newNonce']);
        $nonce = $response->header('Replay-Nonce');
        if (! $response->successful() || ! is_string($nonce) || $nonce === '') {
            throw new RuntimeException('The ACME server did not provide a replay nonce.');
        }

        return $nonce;
    }

    /** @return array{crv:string,kty:string,x:string,y:string} */
    private function jwk(AcmeAccount $account): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($account->private_key_ciphertext));
        if (! is_array($details) || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('The stored ACME account key is invalid.');
        }

        return ['crv' => 'P-256', 'kty' => 'EC', 'x' => $this->base64Url($details['ec']['x']), 'y' => $this->base64Url($details['ec']['y'])];
    }

    private function thumbprint(AcmeAccount $account): string
    {
        return $this->base64Url(hash('sha256', json_encode($this->jwk($account), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true));
    }

    private function newPrivateKey(): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false || ! openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('Unable to generate an ACME account key.');
        }

        return $pem;
    }

    /** @param list<string> $names @return array{string,string} */
    private function certificateRequest(array $names): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false || ! openssl_pkey_export($key, $privateKey)) {
            throw new RuntimeException('Unable to generate the managed certificate key.');
        }
        $configPath = tempnam(sys_get_temp_dir(), 'cdnf-acme-');
        if ($configPath === false) {
            throw new RuntimeException('Unable to allocate temporary CSR configuration.');
        }
        try {
            $san = implode(',', array_map(fn (string $name, int $index): string => 'DNS.'.($index + 1).' = '.$name, $names, array_keys($names)));
            file_put_contents($configPath, "[req]\ndistinguished_name=dn\nreq_extensions=req_ext\nprompt=no\n[dn]\nCN={$names[0]}\n[req_ext]\nsubjectAltName=@alt_names\n[alt_names]\n".str_replace(',', "\n", $san)."\n");
            $csr = openssl_csr_new(['commonName' => $names[0]], $key, ['config' => $configPath, 'req_extensions' => 'req_ext', 'digest_alg' => 'sha256']);
            if ($csr === false || ! openssl_csr_export($csr, $csrPem)) {
                throw new RuntimeException('Unable to create the managed certificate request.');
            }
        } finally {
            @unlink($configPath);
        }
        $der = base64_decode(preg_replace('/-----(?:BEGIN|END) CERTIFICATE REQUEST-----|\s/', '', $csrPem), true);
        if ($der === false) {
            throw new RuntimeException('Unable to encode the managed certificate request.');
        }

        return [$privateKey, $der];
    }

    private function ecdsaDerToRaw(string $der): string
    {
        $offset = 2;
        if (ord($der[1]) > 0x80) {
            $offset = 2 + (ord($der[1]) & 0x7F);
        }
        if (($der[$offset] ?? '') !== "\x02") {
            throw new RuntimeException('Unable to encode the ACME signature.');
        }
        $rLength = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLength);
        $offset += 2 + $rLength;
        $sLength = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $sLength);

        return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT).str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function http(): PendingRequest
    {
        return Http::withOptions(['verify' => (bool) config('services.acme.verify_tls')]);
    }
}
