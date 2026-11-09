<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Looks up when a domain registration expires over RDAP, the registry's JSON lookup service, using the
 * IANA bootstrap list to find the registry for the TLD. Only the expiry date is read and kept. This runs
 * in a queue worker, never in a web request, and a TLD without an RDAP service is reported as unsupported.
 */
class RegistrationExpiryLookup
{
    private const BOOTSTRAP_URL = 'https://data.iana.org/rdap/dns.json';

    /** @return array{status: 'found'|'unsupported'|'unknown', expires: ?CarbonImmutable} */
    public function lookup(string $name): array
    {
        $name = DomainName::normalize($name);
        $labels = explode('.', $name);
        try {
            $base = $this->registryBase(end($labels));
            if ($base === null) {
                return ['status' => 'unsupported', 'expires' => null];
            }
            // The registered name can be a parent of a subdomain zone, so a bounded number of parents is tried.
            for ($attempt = 0; $attempt < 3 && count($labels) >= 2; $attempt++, array_shift($labels)) {
                $response = Http::timeout(8)->withoutRedirecting()->accept('application/rdap+json')
                    ->get($base.'domain/'.implode('.', $labels));
                if ($response->status() === 404) {
                    continue;
                }
                if (! $response->successful() || strlen($response->body()) > 262144) {
                    return ['status' => 'unknown', 'expires' => null];
                }
                foreach ((array) $response->json('events', []) as $event) {
                    if (($event['eventAction'] ?? null) === 'expiration' && is_string($event['eventDate'] ?? null)) {
                        return ['status' => 'found', 'expires' => CarbonImmutable::parse($event['eventDate'])->utc()];
                    }
                }

                return ['status' => 'unknown', 'expires' => null];
            }

            return ['status' => 'unknown', 'expires' => null];
        } catch (Throwable) {
            return ['status' => 'unknown', 'expires' => null];
        }
    }

    /** The registry's HTTPS base URL for the TLD, or null when it publishes no RDAP service. */
    private function registryBase(string $tld): ?string
    {
        $services = Cache::remember('rdap-bootstrap', now()->addDay(), function (): array {
            $json = Http::timeout(10)->get(self::BOOTSTRAP_URL)->throw()->json('services');

            return is_array($json) ? $json : [];
        });
        foreach ($services as $service) {
            if (! in_array($tld, $service[0] ?? [], true)) {
                continue;
            }
            foreach ($service[1] ?? [] as $url) {
                $parts = parse_url((string) $url);
                $host = $parts['host'] ?? '';
                if (($parts['scheme'] ?? '') === 'https' && str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false) {
                    return rtrim((string) $url, '/').'/';
                }
            }
        }

        return null;
    }
}
