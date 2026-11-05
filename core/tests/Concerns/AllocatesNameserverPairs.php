<?php

namespace Tests\Concerns;

use App\Jobs\AllocateNameserverPair;
use App\Models\Domain;
use App\Support\DomainNameserverVerification;
use App\Support\NameserverResolver;

trait AllocatesNameserverPairs
{
    /** Run the queued allocation for a claim against a parent that publishes the given delegation. */
    protected function allocatePair(Domain $domain, array $parentDelegation = ['ns1.registrar.test', 'ns2.registrar.test']): Domain
    {
        (new AllocateNameserverPair($domain->id))->handle($this->parentPublishing($parentDelegation), app(DomainNameserverVerification::class));

        return $domain->refresh();
    }

    protected function parentPublishing(array $delegation): NameserverResolver
    {
        return new class($delegation) extends NameserverResolver
        {
            public function __construct(private array $delegation) {}

            public function delegation(string $domain): array
            {
                return $this->delegation;
            }
        };
    }
}
