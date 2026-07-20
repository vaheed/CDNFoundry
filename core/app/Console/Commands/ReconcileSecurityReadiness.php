<?php

namespace App\Console\Commands;

use App\Actions\DispatchEmergencyMode;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\EmergencyMode;
use App\Models\Operation;
use Illuminate\Console\Command;

class ReconcileSecurityReadiness extends Command
{
    protected $signature = 'security:reconcile-readiness {--limit=100}';

    protected $description = 'Expire emergency controls and advance quiet domains through bounded security recovery';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        EmergencyMode::query()->where('active', true)->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->select(['target_type', 'target_id'])->distinct()->limit($limit)->get()
            ->each(function (EmergencyMode $mode): void {
                $operation = DispatchEmergencyMode::deactivateTarget($mode->target_type, $mode->target_id, null);
                AuditLog::record(null, 'security.emergency_expired', $mode, ['operation_id' => $operation->id]);
            });

        Domain::query()->whereIn('security_state', ['suspected', 'restricted', 'recovering'])->orderBy('id')->limit($limit)->get()
            ->each(function (Domain $domain): void {
                $latest = $domain->securityEvents()->max('occurred_at');
                if ($latest !== null && now()->diffInMinutes($latest, absolute: true) < 10) {
                    return;
                }
                $next = match ($domain->security_state) {
                    'suspected' => 'normal', 'restricted' => 'recovering', 'recovering' => 'normal', default => null,
                };
                if ($next === null) {
                    return;
                }
                $domain->update(['security_state' => $next, 'security_state_changed_at' => now(), 'revision' => $domain->revision + 1]);
                Operation::coalesceDomain('edge.domain_reconcile', $domain->id);
                ReconcileEdgeDomain::dispatch($domain->id);
                AuditLog::record(null, 'security.state_recovered', $domain, ['state' => $next, 'revision' => $domain->revision]);
            });
        $this->info('Security readiness reconciliation completed.');

        return self::SUCCESS;
    }
}
