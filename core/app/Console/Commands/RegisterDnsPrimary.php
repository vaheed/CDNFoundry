<?php

namespace App\Console\Commands;

use App\Jobs\TestDnsCluster;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\Operation;
use Illuminate\Console\Command;

class RegisterDnsPrimary extends Command
{
    protected $signature = 'cdnf:dns:register-primary {--api-url=http://pdns-primary:8081} {--transfer-endpoint= : Address and port DNS nodes transfer zones from} {--nameserver=* : Authoritative nameserver hostname} {--location=Control plane}';

    protected $description = 'Register the private PowerDNS primary as a DNS cluster, test it, and enable it once it is healthy (the API key comes from PDNS_API_KEY)';

    public function handle(): int
    {
        $key = (string) getenv('PDNS_API_KEY');
        $nameservers = array_values(array_unique(array_filter(array_map('strval', (array) $this->option('nameserver')))));
        $endpoint = (string) $this->option('transfer-endpoint');
        if (strlen($key) < 8 || count($nameservers) < 2 || $endpoint === '') {
            $this->error('Give PDNS_API_KEY, --transfer-endpoint, and at least two --nameserver values.');

            return self::FAILURE;
        }
        $apiUrl = (string) $this->option('api-url');
        $cluster = DnsCluster::query()->where('api_url', $apiUrl)->first();
        $fields = ['api_key' => $key, 'transfer_endpoint' => $endpoint];
        if ($cluster === null) {
            $cluster = DnsCluster::query()->create($fields + [
                'name' => 'pdns-primary', 'location' => (string) $this->option('location'), 'api_url' => $apiUrl, 'server_id' => 'localhost',
                'nameservers' => array_map(fn (string $host): array => ['hostname' => $host], $nameservers), 'capacity_zones' => 100000, 'enabled' => false,
            ]);
            AuditLog::record(null, 'dns.cluster_created', $cluster, ['name' => $cluster->name, 'source' => 'fleet']);
        } else {
            $cluster->update($fields);
        }
        if ($cluster->enabled && $cluster->last_health_status === 'healthy') {
            $this->info('The PowerDNS primary is already registered and enabled.');

            return self::SUCCESS;
        }
        $operation = Operation::query()->where('type', 'dns.cluster_test')->whereIn('status', ['pending', 'running'])->where('input->cluster_id', $cluster->id)->first();
        if ($operation === null) {
            $operation = Operation::query()->create(['type' => 'dns.cluster_test', 'status' => 'pending', 'input' => ['cluster_id' => $cluster->id, 'auto_enable' => true]]);
            TestDnsCluster::dispatch($operation->getKey());
        }
        $this->info("Registered {$cluster->name}; its connection test is queued as operation {$operation->getKey()}.");

        return self::SUCCESS;
    }
}
