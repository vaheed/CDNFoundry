<?php

namespace App\Http\Controllers;

use App\Models\DnsDeployment;
use App\Models\Edge;
use App\Models\Operation;
use App\Models\TlsCertificate;
use App\Support\PlatformSettings;
use App\Support\SystemHealth;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MetricsController extends Controller
{
    public function __invoke(Request $request, SystemHealth $health): Response
    {
        $configured = (string) config('services.metrics.token');
        $tokenFile = config('services.metrics.token_file');
        if ($configured === '' && is_string($tokenFile) && is_readable($tokenFile)) {
            $configured = trim((string) file_get_contents($tokenFile));
        }
        abort_if($configured === '' || ! hash_equals($configured, (string) $request->bearerToken()), 404);
        $lines = ['# HELP cdnfoundry_component_health Component health (healthy=1).', '# TYPE cdnfoundry_component_health gauge'];
        foreach ($health->components() as $name => $component) {
            $lines[] = sprintf('cdnfoundry_component_health{component="%s",status="%s"} %d', $name, $component['status'], $component['status'] === 'healthy' ? 1 : 0);
        }
        foreach ($health->queues() as $queue => $state) {
            $lines[] = sprintf('cdnfoundry_queue_depth{queue="%s"} %d', $queue, $state['depth'] ?? 0);
            $lines[] = sprintf('cdnfoundry_queue_oldest_job_age_seconds{queue="%s"} %d', $queue, $state['oldest_job_age_seconds'] ?? 0);
        }
        $lines[] = 'cdnfoundry_operations_failed '.Operation::query()->where('status', 'failed')->count();
        $lines[] = 'cdnfoundry_dns_deployments_drifted '.DnsDeployment::query()->whereIn('status', ['pending', 'failed'])->count();
        $lines[] = 'cdnfoundry_edges_stale '.Edge::query()->where('enabled', true)->where(fn ($query) => $query->whereNull('last_heartbeat_at')->orWhere('last_heartbeat_at', '<', now()->subSeconds(app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds'))))->count();
        $lines[] = 'cdnfoundry_tls_certificates_expiring '.TlsCertificate::query()->where('status', 'active')->where('expires_at', '<=', now()->addDays((int) config('services.acme.expiry_alert_days')))->count();

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
