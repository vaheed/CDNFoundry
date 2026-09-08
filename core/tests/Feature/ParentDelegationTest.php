<?php

namespace Tests\Feature;

use App\Support\NameserverResolver;
use Tests\TestCase;

class ParentDelegationTest extends TestCase
{
    public function test_only_exact_nonrecursive_parent_referrals_are_accepted(): void
    {
        $header = ";; ->>HEADER<<- opcode: QUERY, status: NOERROR, id: 1\n;; flags: qr; QUERY: 1, ANSWER: 0, AUTHORITY: 2;\n";
        $records = "example.com. 300 IN NS a.ns.provider.net.\nexample.com. 300 IN NS b.ns.provider.net.\n";
        $this->assertSame(['a.ns.provider.net', 'b.ns.provider.net'], NameserverResolver::parentRecords($header.$records, 'example.com'));
        $this->assertSame([], NameserverResolver::parentRecords($header.str_replace('example.com.', 'com.', $records), 'example.com'));
        foreach (['aa', 'rd', 'tc'] as $flag) {
            try {
                NameserverResolver::parentRecords(str_replace('flags: qr;', "flags: qr {$flag};", $header).$records, 'example.com');
                $this->fail("Accepted invalid parent response: {$flag}");
            } catch (\RuntimeException) {
            }
        }
    }

    public function test_bogus_dnssec_cannot_be_treated_as_a_successful_empty_response(): void
    {
        $resolver = new class extends NameserverResolver
        {
            protected function execute(array $arguments): string
            {
                return ";; broken trust chain resolving 'com/DS/IN'\n;; resolution failed: broken trust chain\n";
            }
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DNSSEC validation');
        $resolver->resolve('example.com');
    }
}
