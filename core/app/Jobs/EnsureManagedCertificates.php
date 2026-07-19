<?php

namespace App\Jobs;

use App\Enums\DomainLifecycleState;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\TlsOrder;
use App\Support\ManagedCertificateNames;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EnsureManagedCertificates implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public int $domainId, public bool $force = false)
    {
        $this->onQueue('certificate_purge');
    }

    public function handle(): void
    {
        $domain = Domain::query()->find($this->domainId);
        if ($domain === null || $domain->lifecycle_state !== DomainLifecycleState::Active || $domain->nameservers_verified_at === null) {
            return;
        }
        $queued = [];
        foreach (ManagedCertificateNames::requiredSets($domain) as $names) {
            $usable = $domain->tlsCertificates()->where('kind', 'managed')->where('status', 'active')
                ->where('expires_at', '>', now()->addDays((int) config('services.acme.renew_before_days')))->get()
                ->first(fn ($certificate): bool => collect($names)->every(fn (string $name): bool => ManagedCertificateNames::coveredBy($name, $certificate->names)));
            if ($usable !== null && ! $this->force) {
                if ($domain->tls_mode === 'managed' && $domain->active_tls_certificate_id === null && in_array($domain->name, $usable->names, true)) {
                    $domain->forceFill(['active_tls_certificate_id' => $usable->id, 'revision' => $domain->revision + 1])->save();
                    Operation::coalesceDomain('edge.domain_reconcile', $domain->id);
                    ReconcileEdgeDomain::dispatch($domain->id);
                }

                continue;
            }
            $existing = TlsOrder::query()->where('domain_id', $domain->id)->whereIn('status', ['pending', 'creating', 'publishing', 'validating', 'finalizing'])
                ->get()->first(fn (TlsOrder $order): bool => $order->names === $names);
            if ($existing !== null || TlsOrder::query()->where('domain_id', $domain->id)->whereIn('status', ['pending', 'creating', 'publishing', 'validating', 'finalizing'])->count() >= 10) {
                continue;
            }
            $order = TlsOrder::query()->create([
                'domain_id' => $domain->id, 'status' => 'pending', 'names' => $names,
                'names_hash' => hash('sha256', implode("\0", $names)),
                'available_at' => now()->addSeconds(random_int(0, max(0, (int) config('services.acme.initial_jitter_seconds')))),
            ]);
            $queued[] = $order->id;
            Operation::query()->create([
                'type' => 'tls.managed_certificate', 'status' => 'pending',
                'input' => ['domain_id' => $domain->id, 'order_id' => $order->id, 'names' => $names],
            ]);
            IssueManagedCertificate::dispatch($order->id)->delay($order->available_at);
        }
        Operation::query()->whereIn('type', ['tls.managed_reissue', 'tls.managed_renew'])->where('status', 'pending')
            ->where('input->domain_id', $domain->id)->update([
                'status' => 'succeeded', 'result' => ['domain_id' => $domain->id, 'queued_order_ids' => $queued],
                'finished_at' => now(),
            ]);
    }

    public function uniqueId(): string
    {
        return $this->domainId.':'.($this->force ? 'reissue' : 'ensure');
    }
}
