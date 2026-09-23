<?php

namespace App\Console\Commands;

use App\Enums\DomainLifecycleState;
use App\Enums\UserType;
use App\Jobs\EnsureManagedCertificates;
use App\Jobs\IssueManagedCertificate;
use App\Jobs\ReconcileDnsZone;
use App\Models\AcmeChallenge;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\TlsCertificate;
use App\Models\TlsOrder;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DispatchManagedTlsMaintenance extends Command
{
    protected $signature = 'cdnf:tls:dispatch-maintenance {--limit=500}';

    protected $description = 'Queue bounded managed-certificate renewal and publish administrator TLS alerts';

    public function handle(): int
    {
        $limit = min(2000, max(1, (int) $this->option('limit')));
        AcmeChallenge::query()->whereNull('cleaned_at')->where('expires_at', '<=', now())->with('order:id,domain_id')->limit($limit)->get()
            ->filter(fn (AcmeChallenge $challenge): bool => $challenge->order !== null)
            ->groupBy(fn (AcmeChallenge $challenge): int => $challenge->order->domain_id)->each(function ($challenges, int $domainId): void {
                DB::transaction(function () use ($challenges, $domainId): void {
                    $domain = Domain::query()->lockForUpdate()->find($domainId);
                    if ($domain === null) {
                        return;
                    }
                    $changed = AcmeChallenge::query()->whereIn('id', $challenges->pluck('id'))->whereNull('cleaned_at')
                        ->where('expires_at', '<=', now())->update(['status' => 'cleaned', 'cleaned_at' => now()]);
                    if ($changed === 0) {
                        return;
                    }
                    $domain->forceFill(['revision' => $domain->revision + 1])->save();
                    Operation::coalesceDomain('dns.zone_reconcile', $domain->id);
                    ReconcileDnsZone::dispatch($domain->id)->afterCommit();
                });
            });
        $this->queueDomains($limit);
        $this->recoverOrders($limit);

        $admins = User::query()->where('type', UserType::Admin)->whereNull('disabled_at')->get();
        if ($admins->isNotEmpty()) {
            TlsCertificate::query()->where('status', 'active')->whereNull('alerted_at')
                ->where('expires_at', '<=', now()->addDays((int) config('services.acme.expiry_alert_days')))
                ->orderBy('expires_at')->limit($limit)->get()->each(function (TlsCertificate $certificate) use ($admins): void {
                    Notification::make()->danger()->title('TLS certificate expiring')
                        ->body("Domain {$certificate->domain->name}: certificate {$certificate->id} expires {$certificate->expires_at->toIso8601String()}.")
                        ->sendToDatabase($admins);
                    $certificate->update(['alerted_at' => now()]);
                });
            TlsOrder::query()->where('status', 'failed')->whereNull('alerted_at')->latest()->limit($limit)->get()
                ->each(function (TlsOrder $order) use ($admins): void {
                    Notification::make()->danger()->title('Managed TLS issuance failed')
                        ->body("Domain {$order->domain_id}: ".mb_substr($order->last_error ?? 'Unknown ACME error', 0, 500))
                        ->sendToDatabase($admins);
                    $order->update(['alerted_at' => now()]);
                });
        }

        return self::SUCCESS;
    }

    private function recoverOrders(int $limit): void
    {
        TlsOrder::query()->whereIn('status', ['pending', 'publishing', 'validating', 'finalizing'])
            ->where('updated_at', '<=', now()->subMinutes(10))
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()))
            ->orderBy('updated_at')->orderBy('id')->limit($limit)->pluck('id')
            ->each(fn (string $id) => IssueManagedCertificate::dispatch($id));
    }

    private function queueDomains(int $limit): void
    {
        $lock = Cache::lock('tls-maintenance-domain-scan', 300);
        $acquired = $lock->get(function () use ($limit, $lock): bool {
            $progress = Cache::get('tls-maintenance-domain-progress', []);
            $cursor = max(0, (int) ($progress['cursor'] ?? 0));
            $upperId = max(0, (int) ($progress['upper_id'] ?? 0));
            if ($upperId === 0 || $cursor >= $upperId) {
                $cursor = 0;
                $upperId = (int) Domain::query()->max('id');
            }
            $ids = Domain::query()->where('lifecycle_state', DomainLifecycleState::Active)->whereNotNull('nameservers_verified_at')
                ->whereHas('dnsRecords', fn ($query) => $query->where('mode', 'proxied'))
                ->where('id', '>', $cursor)->where('id', '<=', $upperId)->orderBy('id')->limit($limit)->pluck('id');
            foreach ($ids as $id) {
                EnsureManagedCertificates::dispatch((int) $id);
                if (! $lock->refresh()) {
                    throw new RuntimeException('TLS maintenance scan lease was lost; retry the command.');
                }
            }
            // Publish progress only after the batch has been dispatched. A lost
            // cursor or failed dispatch safely repeats work through unique jobs.
            $finished = $ids->count() < $limit || (int) $ids->last() === $upperId;
            if (! $lock->refresh()) {
                throw new RuntimeException('TLS maintenance scan lease was lost; retry the command.');
            }
            if (! Cache::forever('tls-maintenance-domain-progress', [
                'cursor' => $finished ? 0 : (int) $ids->last(),
                'upper_id' => $finished ? 0 : $upperId,
            ])) {
                throw new RuntimeException('Unable to save TLS maintenance progress; retry the command.');
            }

            return true;
        });
        if (! $acquired) {
            $this->warn('TLS maintenance domain scan is already running; this invocation skipped domain dispatch.');
        }
    }
}
