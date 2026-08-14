<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\Operation;
use App\Models\PlatformDnsSetting;
use App\Support\DomainNameserverVerification;
use App\Support\NameserverResolver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class VerifyDomainNameservers implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public int $domainId)
    {
        $this->onQueue('runtime');
    }

    public function handle(NameserverResolver $resolver, ?DomainNameserverVerification $verification = null): void
    {
        $verification ??= app(DomainNameserverVerification::class);
        $operation = $this->operation();
        $operation?->update(['status' => 'running', 'started_at' => now(), 'attempts' => ($operation->attempts ?? 0) + 1]);
        try {
            $domain = Domain::query()->findOrFail($this->domainId);
            if ($domain->lifecycle_state->value === 'deprovisioning') {
                throw new RuntimeException('A deprovisioning domain cannot be verified.');
            }
            $settings = PlatformDnsSetting::query()->find(1) ?? throw new RuntimeException('Platform nameservers are not configured.');
            $expected = collect($settings->nameservers)->pluck('hostname')->map(fn (string $name): string => mb_strtolower(rtrim($name, '.')))->unique()->sort()->values()->all();
            $observed = $resolver->resolve($domain->name);
            if ($observed !== $expected) {
                throw new RuntimeException('Observed nameservers do not exactly match the required platform nameservers.');
            }
            $actor = $operation?->actor()->first();
            $activated = $verification->complete($domain, $actor, $operation, $observed);
        } catch (Throwable $exception) {
            $operation?->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 4000), 'finished_at' => now()]);
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
