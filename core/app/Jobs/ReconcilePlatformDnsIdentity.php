<?php

namespace App\Jobs;

use App\Models\DnsCluster;
use App\Models\Operation;
use App\Models\PlatformDnsDeployment;
use App\Models\PlatformDnsSetting;
use App\Support\PlatformDnsZone;
use App\Support\PowerDnsClient;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ReconcilePlatformDnsIdentity implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public ?string $operationId = null)
    {
        $this->onQueue('runtime');
    }

    public function handle(PowerDnsClient $client): void
    {
        $settings = PlatformDnsSetting::query()->find(1);
        if ($settings === null) {
            return;
        }
        $revision = $settings->revision;
        $rrsets = PlatformDnsZone::render($settings);
        $checksum = hash('sha256', json_encode($rrsets, JSON_THROW_ON_ERROR));
        $zone = rtrim($settings->platform_domain, '.');
        $operation = $this->operationId ? Operation::query()->find($this->operationId) : null;
        $operation?->update([
            'status' => 'running',
            'started_at' => $operation->started_at ?? now(),
            'attempts' => $operation->status === 'running' ? $operation->attempts : $operation->attempts + 1,
        ]);
        $failures = [];
        $targets = 0;

        $configuredTargets = collect($settings->cluster_targets)->map(fn (string $target): string => strtolower($target))->flip();
        $clusters = DnsCluster::query()->where('enabled', true)->where('last_health_status', 'healthy')->orderBy('id')->get()
            ->filter(fn (DnsCluster $cluster): bool => $configuredTargets->has(self::clusterTarget($cluster)));
        foreach ($clusters as $cluster) {
            $targets++;
            $deployment = PlatformDnsDeployment::query()->firstOrCreate(['dns_cluster_id' => $cluster->id]);
            if ($deployment->status === 'succeeded' && $deployment->active_zone === $zone && hash_equals((string) $deployment->active_checksum, $checksum)) {
                continue;
            }
            $deployment->update([
                'desired_revision' => $revision,
                'status' => 'deploying',
                'attempts' => $deployment->attempts + 1,
                'last_attempted_at' => now(),
                'last_error' => null,
            ]);

            try {
                $previousZone = $deployment->active_zone;
                $client->activate($cluster, $zone, $rrsets, $previousZone === null || $previousZone === $zone ? ($deployment->active_rrsets ?? []) : []);
                if ($previousZone !== null && $previousZone !== $zone) {
                    $client->deleteZone($cluster, $previousZone);
                }
                $deployment->update([
                    'deployed_revision' => $revision,
                    'status' => 'succeeded',
                    'active_checksum' => $checksum,
                    'active_zone' => $zone,
                    'active_rrsets' => $rrsets,
                    'deployed_at' => now(),
                ]);
            } catch (Throwable $exception) {
                $message = mb_substr($exception->getMessage(), 0, 4000);
                $deployment->update(['status' => 'failed', 'last_error' => $message]);
                $failures[] = "{$cluster->name}: {$message}";
            }
        }

        if ($failures !== []) {
            $message = implode('; ', $failures);
            $operation?->update(['status' => 'failed', 'error' => mb_substr($message, 0, 4000), 'finished_at' => now()]);
            throw new RuntimeException($message);
        }

        $operation?->update([
            'status' => 'succeeded',
            'result' => ['settings_id' => 1, 'revision' => $revision, 'targets' => $targets],
            'error' => null,
            'finished_at' => now(),
        ]);
    }

    public function uniqueId(): string
    {
        return 'platform-dns-identity';
    }

    public static function dispatchForRoutingChange(): void
    {
        DB::transaction(function (): void {
            $settings = PlatformDnsSetting::query()->lockForUpdate()->find(1);
            if ($settings === null) {
                return;
            }
            $settings->update(['revision' => $settings->revision + 1]);
            self::dispatch()->afterCommit();
        });
    }

    private static function clusterTarget(DnsCluster $cluster): string
    {
        $parts = parse_url($cluster->api_url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (str_contains($host, ':')) {
            $host = '['.$host.']';
        }

        return $host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
