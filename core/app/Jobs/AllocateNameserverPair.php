<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\Operation;
use App\Support\DomainNameserverVerification;
use App\Support\NameserverResolver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

/**
 * Gives a pending claim its nameserver pair. The pair must differ from the delegation the parent
 * zone publishes right now, so the registrant has to change the registrar to prove control, and
 * from every pair another live claim on the same name holds. An unknown or inconsistent parent
 * delegation is retried, never guessed.
 */
class AllocateNameserverPair implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 300;

    public function __construct(public int $domainId)
    {
        $this->onQueue('runtime');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(NameserverResolver $resolver, DomainNameserverVerification $verification): void
    {
        $operation = Operation::query()->where('type', 'domain.nameservers_verify')->whereIn('status', ['pending', 'running'])
            ->where('input->domain_id', $this->domainId)->oldest()->first();
        $domain = Domain::query()->find($this->domainId);
        if ($operation === null || $domain === null || $domain->delegation_nameservers !== null) {
            return;
        }
        $actor = $operation->actor()->first();
        if ($actor === null || Gate::forUser($actor)->denies('update', $domain)) {
            $this->fail($operation, 'The applicant assignment is no longer authorized.');

            return;
        }
        if ($domain->claim_expires_at?->isPast()) {
            $this->fail($operation, 'This pending claim has expired.');

            return;
        }
        $operation->update(['status' => 'running', 'started_at' => $operation->started_at ?? now(), 'attempts' => ($operation->attempts ?? 0) + 1]);
        $current = $resolver->delegation($domain->name);

        DB::transaction(function () use ($domain, $current, $operation, $actor, $verification): bool {
            $locked = Domain::query()->lockForUpdate()->find($domain->id);
            $stillCurrent = Operation::query()->lockForUpdate()->find($operation->id);
            if ($locked === null || $stillCurrent?->status !== 'running' || $locked->claim_expires_at?->isPast()) {
                return false;
            }
            if (! $locked->reserveNameserverPair($current)) {
                $stillCurrent->update([
                    'status' => 'failed', 'finished_at' => now(),
                    'error' => 'No deployed nameserver pair is free for this name right now: every pair is the current delegation, held by another claim, or not yet published. Retry later or ask an administrator.',
                    'result' => ['code' => 'nameserver_pairs_unavailable'],
                ]);

                return false;
            }
            $verification->continueAfterAssignment($locked, $actor, $stillCurrent, true);

            return true;
        });
    }

    public function failed(Throwable $exception): void
    {
        $operation = Operation::query()->where('type', 'domain.nameservers_verify')->whereIn('status', ['pending', 'running'])
            ->where('input->domain_id', $this->domainId)->oldest()->first();
        if ($operation !== null) {
            $this->fail($operation, $exception instanceof RuntimeException ? $exception->getMessage() : 'Nameserver allocation failed.');
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->domainId;
    }

    private function fail(Operation $operation, string $message): void
    {
        $operation->update(['status' => 'failed', 'error' => mb_substr($message, 0, 4000), 'finished_at' => now()]);
    }
}
