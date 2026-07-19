<?php

namespace App\Console\Commands;

use App\Enums\DomainLifecycleState;
use App\Enums\UserType;
use App\Jobs\EnsureManagedCertificates;
use App\Jobs\ReconcileDnsZone;
use App\Models\AcmeChallenge;
use App\Models\Domain;
use App\Models\TlsCertificate;
use App\Models\TlsOrder;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchManagedTlsMaintenance extends Command
{
    protected $signature = 'tls:dispatch-maintenance {--limit=500}';

    protected $description = 'Queue bounded managed-certificate renewal and publish administrator TLS alerts';

    public function handle(): int
    {
        $limit = min(2000, max(1, (int) $this->option('limit')));
        AcmeChallenge::query()->whereNull('cleaned_at')->where('expires_at', '<=', now())->with('order')->limit($limit)->get()
            ->groupBy(fn (AcmeChallenge $challenge): int => $challenge->order->domain_id)->each(function ($challenges, int $domainId): void {
                DB::transaction(function () use ($challenges, $domainId): void {
                    AcmeChallenge::query()->whereIn('id', $challenges->pluck('id'))->whereNull('cleaned_at')->update(['status' => 'cleaned', 'cleaned_at' => now()]);
                    $domain = Domain::query()->lockForUpdate()->find($domainId);
                    if ($domain !== null) {
                        $domain->forceFill(['revision' => $domain->revision + 1])->save();
                        ReconcileDnsZone::dispatch($domain->id)->afterCommit();
                    }
                });
            });
        Domain::query()->where('lifecycle_state', DomainLifecycleState::Active)->whereNotNull('nameservers_verified_at')
            ->whereHas('dnsRecords', fn ($query) => $query->where('mode', 'proxied'))->orderBy('id')->limit($limit)->pluck('id')
            ->each(fn (int $id) => EnsureManagedCertificates::dispatch($id));

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
}
