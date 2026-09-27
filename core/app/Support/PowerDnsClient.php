<?php

namespace App\Support;

use App\Models\DnsCluster;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PowerDnsClient
{
    public function health(DnsCluster $cluster): void
    {
        $this->request($cluster)->get('/api/v1/servers/'.$cluster->server_id)->throw();
    }

    /** @param list<array{name:string,type:string,ttl:int,records:list<array{content:string,disabled:bool}>}> $rrsets */
    public function activate(DnsCluster $cluster, string $zone, array $rrsets, array $previousRrsets): void
    {
        $zoneId = rawurlencode($zone.'.');
        $request = $this->request($cluster);
        $exists = $request->get("/api/v1/servers/{$cluster->server_id}/zones/$zoneId");
        $created = $exists->status() === 404;
        if ($created) {
            $nameservers = collect($cluster->nameservers)->map(fn ($item): string => rtrim(is_array($item) ? $item['hostname'] : $item, '.').'.')->values()->all();
            $request->post("/api/v1/servers/{$cluster->server_id}/zones", [
                'name' => $zone.'.', 'kind' => 'Native', 'masters' => [], 'nameservers' => $nameservers,
            ])->throw();
        } else {
            $exists->throw();
        }

        $nextKeys = collect($rrsets)->mapWithKeys(fn (array $rrset): array => [$rrset['name'].'|'.$rrset['type'] => true]);
        $deletes = collect($previousRrsets)
            ->reject(fn (array $rrset): bool => $nextKeys->has($rrset['name'].'|'.$rrset['type']))
            ->map(fn (array $rrset): array => ['name' => $rrset['name'], 'type' => $rrset['type'], 'changetype' => 'DELETE', 'records' => []]);
        $replacements = collect($rrsets)->map(fn (array $rrset): array => [...$rrset, 'changetype' => 'REPLACE']);

        $request->patch("/api/v1/servers/{$cluster->server_id}/zones/$zoneId", [
            'rrsets' => $deletes->concat($replacements)->values()->all(),
        ])->throw();

        $actual = $request->get("/api/v1/servers/{$cluster->server_id}/zones/$zoneId")->throw()->json('rrsets');
        if (! is_array($actual) || ! self::sameRrsets($rrsets, $actual, $deletes->all())) {
            try {
                if ($created) {
                    $this->deleteZone($cluster, $zone);
                    $restored = $request->get("/api/v1/servers/{$cluster->server_id}/zones/$zoneId");
                    if ($restored->status() !== 404) {
                        throw new RuntimeException('New invalid PowerDNS zone remained after rollback.');
                    }
                } else {
                    $nextDeletes = collect($rrsets)->reject(fn (array $rrset): bool => collect($previousRrsets)->contains(fn (array $previous): bool => $previous['name'] === $rrset['name'] && $previous['type'] === $rrset['type']))
                        ->map(fn (array $rrset): array => ['name' => $rrset['name'], 'type' => $rrset['type'], 'changetype' => 'DELETE', 'records' => []]);
                    $restore = collect($previousRrsets)->map(fn (array $rrset): array => [...$rrset, 'changetype' => 'REPLACE']);
                    $request->patch("/api/v1/servers/{$cluster->server_id}/zones/$zoneId", ['rrsets' => $nextDeletes->concat($restore)->values()->all()])->throw();
                    $restored = $request->get("/api/v1/servers/{$cluster->server_id}/zones/$zoneId")->throw()->json('rrsets');
                    if (! is_array($restored) || ! self::sameRrsets($previousRrsets, $restored, $nextDeletes->all())) {
                        throw new RuntimeException('Prior PowerDNS RRsets did not return after rollback.');
                    }
                }
            } catch (\Throwable $exception) {
                throw new RuntimeException('PowerDNS read-back mismatch and rollback failed.', previous: $exception);
            }
            throw new RuntimeException('PowerDNS read-back did not match the desired RRsets; prior state verified after rollback.');
        }
    }

    private static function sameRrsets(array $expected, array $actual, array $absent = []): bool
    {
        $soa = static function (array $rrsets): ?array {
            foreach ($rrsets as $rrset) {
                if (($rrset['type'] ?? null) !== 'SOA') {
                    continue;
                }
                $parts = preg_split('/\s+/', $rrset['records'][0]['content'] ?? '');
                if (count($parts) !== 7 || ! ctype_digit($parts[2])) {
                    return null;
                }

                return [$rrset['name'], $parts];
            }

            return [];
        };
        $wantedSoa = $soa($expected);
        $observedSoa = $soa($actual);
        if ($wantedSoa === null || $observedSoa === null || count($wantedSoa) !== count($observedSoa)) {
            return false;
        }
        if ($wantedSoa !== [] && ($wantedSoa[0] !== $observedSoa[0]
            || (int) $observedSoa[1][2] < (int) $wantedSoa[1][2])) {
            return false;
        }

        $canonical = static function (array $rrsets): array {
            $rows = [];
            foreach ($rrsets as $rrset) {
                if (! is_array($rrset) || ! is_string($rrset['name'] ?? null) || ! is_string($rrset['type'] ?? null) || ! is_array($rrset['records'] ?? null)) {
                    return [];
                }
                $key = $rrset['name'].'|'.$rrset['type'];
                $records = collect($rrset['records'])->map(fn ($record): array => [
                    $rrset['type'] === 'SOA'
                        ? preg_replace('/^(\S+\s+\S+\s+)\d+(\s+.*)$/', '$1<serial>$2', (string) ($record['content'] ?? ''))
                        : (string) ($record['content'] ?? ''),
                    (bool) ($record['disabled'] ?? false),
                ])->sortBy(fn (array $record): string => $record[0].'|'.(int) $record[1])->values()->all();
                $rows[$key] = [(int) ($rrset['ttl'] ?? 0), $records];
            }
            ksort($rows);

            return $rows;
        };

        $wanted = $canonical($expected);
        $observed = $canonical($actual);
        foreach ($wanted as $key => $value) {
            if (($observed[$key] ?? null) !== $value) {
                return false;
            }
        }
        foreach ($absent as $rrset) {
            if (isset($observed[$rrset['name'].'|'.$rrset['type']])) {
                return false;
            }
        }

        return true;
    }

    public function deleteZone(DnsCluster $cluster, string $zone): void
    {
        $response = $this->request($cluster)->delete('/api/v1/servers/'.$cluster->server_id.'/zones/'.rawurlencode($zone.'.'));
        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    private function request(DnsCluster $cluster): PendingRequest
    {
        $request = Http::baseUrl(rtrim($cluster->api_url, '/'))
            ->withHeader('X-API-Key', $cluster->api_key)->acceptJson()->asJson()
            ->connectTimeout(2)->timeout(10)->retry(2, 100, throw: false);
        $caCertificate = config('services.powerdns.ca_certificate');

        return is_string($caCertificate) && $caCertificate !== ''
            ? $request->withOptions(['verify' => $caCertificate])
            : $request;
    }
}
