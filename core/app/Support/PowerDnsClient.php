<?php

namespace App\Support;

use App\Models\DnsCluster;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

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
        if ($exists->status() === 404) {
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
        return Http::baseUrl(rtrim($cluster->api_url, '/'))
            ->withHeader('X-API-Key', $cluster->api_key)->acceptJson()->asJson()
            ->connectTimeout(2)->timeout(10)->retry(2, 100, throw: false);
    }
}
