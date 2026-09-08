<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\Operation;
use App\Support\DomainNameserverVerification;
use App\Support\NameserverResolver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

class VerifyDomainNameservers implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(public int $domainId)
    {
        $this->onQueue('runtime');
    }

    public function handle(NameserverResolver $resolver, ?DomainNameserverVerification $verification = null): void
    {
        $verification ??= app(DomainNameserverVerification::class);
        $operation = $this->operation();
        if ($operation === null) {
            return;
        }
        $operation?->update(['status' => 'running', 'started_at' => now(), 'attempts' => ($operation->attempts ?? 0) + 1]);
        try {
            $domain = Domain::query()->findOrFail($this->domainId);
            if (! in_array($domain->lifecycle_state->value, ['pending_verification', 'active'], true)) {
                throw new RuntimeException('Only pending or active domains can be verified.');
            }
            $actor = $operation->actor()->first();
            if ($actor === null || Gate::forUser($actor)->denies('update', $domain)) {
                throw new RuntimeException('The applicant assignment is no longer authorized.');
            }
            if ($domain->nameservers_verified_at === null && ($domain->claim_expires_at?->isPast()
                || ($operation->input['delegation_token'] ?? null) !== $domain->delegation_token)) {
                throw new RuntimeException('The delegation claim expired or changed. Request verification again.');
            }
            $expected = $domain->assignedNameservers();
            if (count($expected) < 2) {
                throw new RuntimeException('Request nameserver verification again to initialize this delegation assignment.');
            }
            $observed = $resolver->resolve($domain->name);
            if ($observed !== $expected) {
                throw new RuntimeException('Observed nameservers do not exactly match the nameservers assigned to this claim.');
            }
            $activated = $verification->complete($domain, $actor, $operation, $observed);
        } catch (Throwable $exception) {
            Operation::query()->whereKey($operation->id)->whereIn('status', ['pending', 'running'])
                ->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 4000), 'finished_at' => now()]);
            throw $exception;
        }
        if ($activated) {
            $verification->dispatchActivation($domain->id, $actor?->getKey());
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->domainId;
    }

    private function operation(): ?Operation
    {
        return Operation::query()->where('type', 'domain.nameservers_verify')->whereIn('status', ['pending', 'running'])
            ->where('input->domain_id', $this->domainId)->oldest()->first();
    }
}
