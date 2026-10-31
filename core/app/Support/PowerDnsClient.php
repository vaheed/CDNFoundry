<?php

namespace App\Support;

use App\Models\DnsCluster;
use App\Models\DnsZoneChange;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PowerDnsClient
{
    /** The catalog zone every served zone is a member of. DNS nodes consume it to learn the zone list. */
    public const CATALOG_ZONE = 'catalog.cdnfoundry.invalid.';

    /**
     * Each write the primary stores the rendered serial plus one. The rendered serial is the domain's
     * revision, which only grows, so every change raises the serial a node compares before it pulls.
     * PowerDNS's default instead keeps a date-based serial that a same-day change does not raise.
     */
    public const SOA_EDIT = 'INCREASE';

    /** The TSIG key that signs zone transfers; every node holds the same secret. */
    public const TRANSFER_KEY = 'cdnf-nodes';

    public function health(DnsCluster $cluster): void
    {
        $this->request($cluster)->get('/api/v1/servers/'.$cluster->server_id)->throw();
        // Testing the connection also prepares the primary for transfers, and repairs it if it drifted.
        $this->ensureTransferSetup($cluster);
    }

    /** @param list<array{name:string,type:string,ttl:int,records:list<array{content:string,disabled:bool}>}> $rrsets */
    public function activate(DnsCluster $cluster, string $zone, array $rrsets, array $previousRrsets): void
    {
        $zoneId = rawurlencode($zone.'.');
        $request = $this->request($cluster);
        $exists = $request->get("/api/v1/servers/{$cluster->server_id}/zones/$zoneId");
        $created = $exists->status() === 404;
        if ($created) {
            $this->ensureTransferSetup($cluster);
            $nameservers = collect($cluster->nameservers)->map(fn ($item): string => rtrim(is_array($item) ? $item['hostname'] : $item, '.').'.')->values()->all();
            // A primary-kind zone that belongs to the catalog: DNS nodes learn of it from the catalog
            // and pull it, signed with the transfer key.
            $request->post("/api/v1/servers/{$cluster->server_id}/zones", [
                'name' => $zone.'.', 'kind' => 'Master', 'masters' => [], 'nameservers' => $nameservers, 'catalog' => self::CATALOG_ZONE,
                'soa_edit_api' => self::SOA_EDIT,
            ])->throw();
            $this->allowTransfer($cluster, $zone.'.');
        } else {
            $exists->throw();
            if ($exists->json('kind') !== 'Master' || $exists->json('catalog') !== self::CATALOG_ZONE || $exists->json('soa_edit_api') !== self::SOA_EDIT) {
                $this->ensureTransferSetup($cluster);
                $request->put("/api/v1/servers/{$cluster->server_id}/zones/$zoneId", ['kind' => 'Master', 'catalog' => self::CATALOG_ZONE, 'soa_edit_api' => self::SOA_EDIT])->throw();
                $this->allowTransfer($cluster, $zone.'.');
            }
        }

        $nextKeys = collect($rrsets)->mapWithKeys(fn (array $rrset): array => [$rrset['name'].'|'.$rrset['type'] => true]);
        $deletes = collect($previousRrsets)
            ->reject(fn (array $rrset): bool => $nextKeys->has($rrset['name'].'|'.$rrset['type']))
            ->map(fn (array $rrset): array => ['name' => $rrset['name'], 'type' => $rrset['type'], 'changetype' => 'DELETE', 'records' => []]);
        $replacements = collect($rrsets)->map(fn (array $rrset): array => [...$rrset, 'changetype' => 'REPLACE']);

        $request->patch("/api/v1/servers/{$cluster->server_id}/zones/$zoneId", [
            'rrsets' => $deletes->concat($replacements)->values()->all(),
        ])->throw();

        $readBack = $request->get("/api/v1/servers/{$cluster->server_id}/zones/$zoneId")->throw();
        $actual = $readBack->json('rrsets');
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
        // PowerDNS may rewrite the SOA serial (SOA-EDIT-API), and a node compares what it serves with
        // the serial the primary really holds, so record that one.
        DnsZoneChange::record($zone, (int) ($readBack->json('serial') ?: self::serialOf($rrsets)));
    }

    /**
     * The transfer key and the catalog zone must exist on the primary before the first zone is
     * created. Both calls are idempotent.
     */
    public function ensureTransferSetup(DnsCluster $cluster): void
    {
        $request = $this->request($cluster);
        $key = $request->get("/api/v1/servers/{$cluster->server_id}/tsigkeys/".self::TRANSFER_KEY);
        if ($key->status() === 404) {
            $request->post("/api/v1/servers/{$cluster->server_id}/tsigkeys", [
                'name' => self::TRANSFER_KEY, 'algorithm' => 'hmac-sha256', 'key' => $cluster->transferSecret(),
            ])->throw();
        } else {
            $key->throw();
            // A key that exists with another secret (created before the secret was recorded) would
            // make every node's transfer fail; the stored secret is the truth.
            if ($key->json('key') !== $cluster->transferSecret()) {
                $request->put("/api/v1/servers/{$cluster->server_id}/tsigkeys/".self::TRANSFER_KEY, [
                    'name' => self::TRANSFER_KEY, 'algorithm' => 'hmac-sha256', 'key' => $cluster->transferSecret(),
                ])->throw();
            }
        }
        $catalog = $request->get("/api/v1/servers/{$cluster->server_id}/zones/".rawurlencode(self::CATALOG_ZONE));
        if ($catalog->status() === 404) {
            $request->post("/api/v1/servers/{$cluster->server_id}/zones", [
                'name' => self::CATALOG_ZONE, 'kind' => 'Producer', 'nameservers' => [],
            ])->throw();
            $this->allowTransfer($cluster, self::CATALOG_ZONE);
        } else {
            $catalog->throw();
        }
    }

    /** Only a request signed with the transfer key may transfer the zone. */
    private function allowTransfer(DnsCluster $cluster, string $zone): void
    {
        $this->request($cluster)->post("/api/v1/servers/{$cluster->server_id}/zones/".rawurlencode($zone).'/metadata', [
            'kind' => 'TSIG-ALLOW-AXFR', 'metadata' => [self::TRANSFER_KEY],
        ])->throw();
    }

    /** @param list<array{name:string,type:string,ttl:int,records:list<array{content:string,disabled:bool}>}> $rrsets */
    private static function serialOf(array $rrsets): int
    {
        foreach ($rrsets as $rrset) {
            if ($rrset['type'] === 'SOA' && isset($rrset['records'][0]['content'])) {
                return (int) (explode(' ', $rrset['records'][0]['content'])[2] ?? 0);
            }
        }

        return 0;
    }

    /**
     * Whether the zone a cluster serves still matches the RRsets last deployed to it.
     * A missing zone or a differing record set (including a lower SOA serial) is drift.
     * Connection and server errors are thrown, never reported as drift.
     *
     * @param  list<array{name:string,type:string,ttl:int,records:list<array{content:string,disabled:bool}>}>  $rrsets
     */
    public function matches(DnsCluster $cluster, string $zone, array $rrsets): bool
    {
        $response = $this->request($cluster)->get('/api/v1/servers/'.$cluster->server_id.'/zones/'.rawurlencode($zone.'.'));
        if ($response->status() === 404) {
            return false;
        }
        $actual = $response->throw()->json('rrsets');

        return is_array($actual) && self::sameRrsets($rrsets, $actual);
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
        DnsZoneChange::record($zone, 0, deleted: true);
    }

    private function request(DnsCluster $cluster): PendingRequest
    {
        // The primary's API is reachable only on the private control network.
        return Http::baseUrl(rtrim($cluster->api_url, '/'))
            ->withHeader('X-API-Key', $cluster->api_key)->acceptJson()->asJson()
            ->connectTimeout(2)->timeout(10)->retry(2, 100, throw: false);
    }
}
