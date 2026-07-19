<?php

namespace App\Jobs;

use App\Models\AcmeAccount;
use App\Models\DnsCluster;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\TlsOrder;
use App\Support\AcmeClient;
use App\Support\ManagedCertificateNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Throwable;

class IssueManagedCertificate implements ShouldQueue
{
    use Queueable;

    public int $tries = 500;

    public int $maxExceptions = 5;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public string $orderId)
    {
        $this->onQueue('certificate_purge');
    }

    public function handle(AcmeClient $client): void
    {
        $order = TlsOrder::query()->with(['challenges'])->find($this->orderId);
        if ($order === null || in_array($order->status, ['succeeded', 'failed', 'obsolete'], true)) {
            return;
        }
        $maximumOrderMinutes = max(60, (int) config('services.acme.challenge_lifetime_minutes') + 30);
        if ($order->created_at->lte(now()->subMinutes($maximumOrderMinutes))) {
            $this->failed(new RuntimeException('Managed certificate issuance exceeded its bounded order lifetime.'));

            return;
        }
        if ($order->available_at?->isFuture() || $order->next_poll_at?->isFuture()) {
            $this->release(max(1, now()->diffInSeconds($order->available_at?->isFuture() ? $order->available_at : $order->next_poll_at)));

            return;
        }
        $domain = Domain::query()->find($order->domain_id);
        if ($domain === null || ! collect(ManagedCertificateNames::requiredSets($domain))->contains(fn (array $set): bool => $set === $order->names)) {
            $this->obsolete($order);

            return;
        }
        $operation = $this->operation();
        $operation?->update(['status' => 'running', 'attempts' => ($operation->attempts ?? 0) + 1, 'started_at' => $operation->started_at ?? now()]);
        try {
            match ($order->status) {
                'pending' => $this->create($order, $client),
                'publishing' => $this->publish($order, $client),
                'validating' => $this->validate($order, $client),
                'finalizing' => $this->finalize($order, $client),
                default => throw new RuntimeException("Unknown managed certificate order state: {$order->status}"),
            };
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 4000);
            $order->forceFill(['attempts' => $order->attempts + 1, 'last_error' => $message, 'available_at' => now()->addSeconds($this->retryDelay($order->attempts))])->save();
            $operation?->update(['status' => 'pending', 'error' => $message]);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $order = TlsOrder::query()->find($this->orderId);
        if ($order === null || in_array($order->status, ['succeeded', 'obsolete'], true)) {
            return;
        }
        $message = mb_substr($exception?->getMessage() ?? $order->last_error ?? 'Managed certificate issuance exhausted its retry budget.', 0, 4000);
        DB::transaction(function () use ($order, $message): void {
            $order->forceFill(['status' => 'failed', 'last_error' => $message, 'finished_at' => now()])->save();
            $order->challenges()->whereNull('cleaned_at')->update(['status' => 'cleaned', 'cleaned_at' => now()]);
            $domain = Domain::query()->lockForUpdate()->find($order->domain_id);
            if ($domain !== null && $order->dns_revision !== null) {
                $domain->forceFill(['revision' => $domain->revision + 1])->save();
                ReconcileDnsZone::dispatch($domain->id)->afterCommit();
            }
            $this->operation()?->update(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);
        });
    }

    private function create(TlsOrder $order, AcmeClient $client): void
    {
        $allowed = false;
        RateLimiter::attempt(
            'acme:new-order',
            max(1, (int) config('services.acme.order_budget_per_hour')),
            function () use (&$allowed): void {
                $allowed = true;
            },
            3600,
        );
        if (! $allowed) {
            $order->update(['available_at' => now()->addSeconds(300 + random_int(0, 300)), 'last_error' => 'The global ACME order budget is currently exhausted.']);
            $this->release(300 + random_int(0, 300));

            return;
        }
        $account = $client->account();
        $remote = $client->createOrder($account, $order->names);
        $challenges = [];
        foreach ($remote['authorization_urls'] as $authorizationUrl) {
            $challenge = $client->dnsChallenge($account, $authorizationUrl);
            if (! ($challenge['already_valid'] ?? false)) {
                unset($challenge['already_valid']);
                $challenges[] = $challenge;
            }
        }
        DB::transaction(function () use ($order, $account, $remote, $challenges): void {
            $locked = TlsOrder::query()->lockForUpdate()->findOrFail($order->id);
            $domain = Domain::query()->lockForUpdate()->findOrFail($locked->domain_id);
            if ($challenges !== []) {
                $domain->forceFill(['revision' => $domain->revision + 1])->save();
            }
            $locked->forceFill([
                'acme_account_id' => $account->id, 'status' => $challenges === [] ? 'validating' : 'publishing', 'acme_order_url' => $remote['order_url'],
                'authorization_urls' => $remote['authorization_urls'], 'finalize_url' => $remote['finalize_url'],
                'dns_revision' => $challenges === [] ? null : $domain->revision, 'started_at' => $locked->started_at ?? now(),
                'last_error' => null, 'next_poll_at' => now()->addSeconds(5),
            ])->save();
            foreach ($challenges as $challenge) {
                $locked->challenges()->create([...$challenge, 'status' => 'published', 'expires_at' => now()->addMinutes((int) config('services.acme.challenge_lifetime_minutes'))]);
            }
            if ($challenges !== []) {
                Operation::coalesceDomain('dns.zone_reconcile', $domain->id);
                ReconcileDnsZone::dispatch($domain->id)->afterCommit();
            }
        });
        $this->release(5);
    }

    private function publish(TlsOrder $order, AcmeClient $client): void
    {
        $clusters = DnsCluster::query()->where('enabled', true)->count();
        $deployed = $order->dns_revision !== null && $order->domain_id !== null
            ? Domain::query()->findOrFail($order->domain_id)->dnsDeployments()
                ->whereHas('cluster', fn ($query) => $query->where('enabled', true))
                ->where('status', 'succeeded')->where('deployed_revision', '>=', $order->dns_revision)->count()
            : 0;
        if ($clusters === 0 || $deployed !== $clusters) {
            if ($clusters > 0 && $order->domain_id !== null) {
                Operation::coalesceDomain('dns.zone_reconcile', $order->domain_id);
                ReconcileDnsZone::dispatch($order->domain_id)->afterCommit();
            }
            $order->update(['next_poll_at' => now()->addSeconds(10)]);
            $this->release(10);

            return;
        }
        $account = $order->acme_account_id ? AcmeAccount::query()->findOrFail($order->acme_account_id) : $client->account();
        foreach ($order->challenges as $challenge) {
            $client->acknowledgeChallenge($account, $challenge->challenge_url);
            $challenge->update(['status' => 'validating']);
        }
        $order->update(['status' => 'validating', 'next_poll_at' => now()->addSeconds(5), 'last_error' => null]);
        $this->release(5);
    }

    private function validate(TlsOrder $order, AcmeClient $client): void
    {
        $account = AcmeAccount::query()->findOrFail($order->acme_account_id);
        $pending = false;
        foreach ($order->challenges as $challenge) {
            $state = $client->authorizationStatus($account, $challenge->authorization_url);
            if ($state['status'] === 'invalid') {
                throw new RuntimeException('ACME DNS validation failed: '.($state['error'] ?? $challenge->hostname));
            }
            if ($state['status'] !== 'valid') {
                $pending = true;
            } else {
                $challenge->update(['status' => 'valid']);
            }
        }
        if ($pending) {
            $order->update(['next_poll_at' => now()->addSeconds(10)]);
            $this->release(10);

            return;
        }
        $request = $client->finalizeOrder($account, $order->finalize_url, $order->names);
        $order->update([
            'status' => 'finalizing', 'private_key_ciphertext' => $request['private_key'], 'csr_der' => $request['csr_der'],
            'next_poll_at' => now()->addSeconds(5), 'last_error' => null,
        ]);
        $this->release(5);
    }

    private function finalize(TlsOrder $order, AcmeClient $client): void
    {
        $account = AcmeAccount::query()->findOrFail($order->acme_account_id);
        $remote = $client->orderStatus($account, $order->acme_order_url);
        if ($remote['status'] === 'invalid') {
            throw new RuntimeException('ACME order finalization failed: '.($remote['error'] ?? 'invalid order'));
        }
        if ($remote['status'] !== 'valid' || $remote['certificate_url'] === null) {
            $order->update(['next_poll_at' => now()->addSeconds(10)]);
            $this->release(10);

            return;
        }
        $bundle = $client->downloadCertificate($account, $remote['certificate_url']);
        $parsed = openssl_x509_parse($bundle['certificate_pem'], false);
        if (! is_array($parsed) || ! isset($parsed['validFrom_time_t'], $parsed['validTo_time_t'])) {
            throw new RuntimeException('The issued certificate could not be parsed.');
        }
        $issuedNames = collect(explode(',', (string) ($parsed['extensions']['subjectAltName'] ?? '')))
            ->map(fn (string $name): string => strtolower(trim(preg_replace('/^DNS:/i', '', trim($name)))))
            ->filter()->values()->all();
        if (now()->timestamp < $parsed['validFrom_time_t'] || now()->timestamp >= $parsed['validTo_time_t']
            || ! collect($order->names)->every(fn (string $name): bool => ManagedCertificateNames::coveredBy($name, $issuedNames))) {
            throw new RuntimeException('The issued certificate is not currently valid for every requested hostname.');
        }
        $public = openssl_pkey_get_details(openssl_pkey_get_public($bundle['certificate_pem']));
        $private = openssl_pkey_get_details(openssl_pkey_get_private($order->private_key_ciphertext));
        if (! is_array($public) || ! is_array($private) || ! hash_equals($public['key'], $private['key'])) {
            throw new RuntimeException('The issued certificate does not match its generated private key.');
        }
        DB::transaction(function () use ($order, $bundle, $parsed, $remote): void {
            $locked = TlsOrder::query()->lockForUpdate()->findOrFail($order->id);
            $domain = Domain::query()->lockForUpdate()->findOrFail($locked->domain_id);
            $certificate = $domain->tlsCertificates()->create([
                'kind' => 'managed', 'status' => 'active', 'certificate_pem' => $bundle['certificate_pem'],
                'chain_pem' => $bundle['chain_pem'], 'private_key_ciphertext' => $locked->private_key_ciphertext,
                'names' => $locked->names, 'fingerprint_sha256' => bin2hex(openssl_x509_fingerprint($bundle['certificate_pem'], 'sha256', true)),
                'not_before' => now()->setTimestamp($parsed['validFrom_time_t']), 'expires_at' => now()->setTimestamp($parsed['validTo_time_t']),
                'activated_at' => now(),
            ]);
            $domain->tlsCertificates()->where('kind', 'managed')->where('id', '!=', $certificate->id)->where('status', 'active')->get()
                ->filter(fn ($other): bool => $other->names === $certificate->names)->each->update(['status' => 'superseded']);
            if ($domain->tls_mode === 'managed' && in_array($domain->name, $locked->names, true)) {
                $domain->active_tls_certificate_id = $certificate->id;
            }
            $domain->revision++;
            $domain->save();
            $locked->challenges()->whereNull('cleaned_at')->update(['status' => 'cleaned', 'cleaned_at' => now()]);
            $locked->update([
                'certificate_id' => $certificate->id, 'certificate_url' => $remote['certificate_url'],
                'status' => 'succeeded', 'finished_at' => now(), 'next_poll_at' => null, 'last_error' => null,
            ]);
            $this->operation()?->update([
                'status' => 'succeeded', 'result' => ['domain_id' => $domain->id, 'order_id' => $locked->id, 'certificate_id' => $certificate->id],
                'error' => null, 'finished_at' => now(),
            ]);
            Operation::coalesceDomain('dns.zone_reconcile', $domain->id);
            Operation::coalesceDomain('edge.domain_reconcile', $domain->id);
            ReconcileDnsZone::dispatch($domain->id)->afterCommit();
            ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();
        });
    }

    private function obsolete(TlsOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $order->update(['status' => 'obsolete', 'finished_at' => now(), 'last_error' => 'The proxied hostname set changed before issuance completed.']);
            if ($order->challenges()->whereNull('cleaned_at')->exists()) {
                $order->challenges()->whereNull('cleaned_at')->update(['status' => 'cleaned', 'cleaned_at' => now()]);
                $domain = Domain::query()->lockForUpdate()->find($order->domain_id);
                if ($domain !== null) {
                    $domain->forceFill(['revision' => $domain->revision + 1])->save();
                    ReconcileDnsZone::dispatch($domain->id)->afterCommit();
                }
            }
        });
        $this->operation()?->update(['status' => 'failed', 'error' => $order->last_error, 'finished_at' => now()]);
    }

    private function operation(): ?Operation
    {
        return Operation::query()->where('type', 'tls.managed_certificate')->where('input->order_id', $this->orderId)->latest()->first();
    }

    private function retryDelay(int $attempts): int
    {
        return min(3600, 60 * (2 ** min(6, max(0, $attempts - 1)))) + random_int(0, 30);
    }
}
