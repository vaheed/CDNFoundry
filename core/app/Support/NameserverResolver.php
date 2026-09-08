<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

class NameserverResolver
{
    private float $deadline;

    /**
     * Validate through BIND's root trust anchor, then query the actual parent
     * authorities directly. Child-zone apex NS data alone never proves a claim.
     * This external boundary is called by a queue worker, never a web request.
     *
     * @return list<string>
     */
    public function resolve(string $domain): array
    {
        $domain = DomainName::normalize($domain);
        $this->deadline = microtime(true) + 45;
        // Runtime zones are unsigned. Validate parent-side DS absence; a stale
        // DS or bogus chain must be corrected before switching delegation.
        if ($this->validatedRecords($domain, 'DS', allowNegative: true) !== []) {
            throw new RuntimeException('Remove the previous provider DS record before activating this unsigned zone.');
        }
        $labels = explode('.', $domain);
        $parents = [];
        while (count($labels) > 1) {
            array_shift($labels);
            $parent = implode('.', $labels);
            $parents = $this->validatedRecords($parent, 'NS', allowNegative: true);
            if ($parents !== []) {
                break;
            }
        }
        if ($parents === [] || count($parents) > 16) {
            throw new RuntimeException('A bounded parent authority set could not be established.');
        }
        $observed = null;
        foreach ($parents as $parentServer) {
            $addresses = array_merge($this->validatedRecords($parentServer, 'A', allowNegative: true), $this->validatedRecords($parentServer, 'AAAA', allowNegative: true));
            if ($addresses === []) {
                throw new RuntimeException('A parent authority address could not be validated.');
            }
            $answer = null;
            foreach (array_slice($addresses, 0, 4) as $address) {
                if (NetworkAddress::isUnsafe($address)) {
                    throw new RuntimeException('A parent authority resolved to a nonpublic address.');
                }
                try {
                    $output = $this->execute(['dig', '@'.$address, $domain.'.', 'NS', '+norecurse', '+time=2', '+tries=1', '+noall', '+comments', '+answer', '+authority']);
                    $answer = self::parentRecords($output, $domain);
                    break;
                } catch (RuntimeException) {
                    // Address-family fallback is bounded; an inconsistent valid
                    // answer below is never ignored in favor of another server.
                }
            }
            if ($answer === null || $answer === []) {
                throw new RuntimeException('A parent authority did not return the exact delegation. Retry after propagation.');
            }
            if ($observed !== null && $observed !== $answer) {
                throw new RuntimeException('Parent authorities disagree on the delegation. Retry after propagation.');
            }
            $observed = $answer;
        }

        return $observed;
    }

    /** @return list<string> */
    protected function validatedRecords(string $name, string $type, bool $allowNegative = false): array
    {
        $output = $this->execute(['delv', '-q', $name.'.', '-t', $type, '+nottl', '+noclass', '+nodnssec']);
        if ($allowNegative && preg_match('/resolution failed: (?:ncache )?(?:nxdomain|nxrrset)/i', $output)) {
            return [];
        }
        if (! preg_match('/; (?:fully validated|unsigned answer)/', $output) || str_contains($output, 'resolution failed:')) {
            throw new RuntimeException('DNSSEC validation or DNS resolution failed. Check delegation, DS records, resolver reachability and the system clock.');
        }

        return self::records($output, $name, $type, false);
    }

    /** @return list<string> */
    public static function parentRecords(string $output, string $domain): array
    {
        if (! preg_match('/status: NOERROR,/', $output) || preg_match('/flags:[^;]*\b(?:tc|rd|aa)\b/', $output)) {
            throw new RuntimeException('Parent response was unsuccessful, truncated or recursive.');
        }

        return self::records($output, $domain, 'NS', true);
    }

    /** @return list<string> */
    private static function records(string $output, string $name, string $type, bool $withTtl): array
    {
        $middle = $withTtl ? '\\s+\\d+\\s+IN' : '';
        preg_match_all('/^'.preg_quote($name, '/').'\\.'.$middle.'\\s+'.preg_quote($type, '/').'\\s+([^;]+?)\\s*$/mi', $output, $matches);
        $records = array_values(array_unique(array_map(fn (string $value): string => strtolower(rtrim($value, '.')), $matches[1])));
        sort($records);

        return $records;
    }

    protected function execute(array $arguments): string
    {
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('Parent delegation verification exceeded its 45-second budget.');
        }
        $process = new Process($arguments, timeout: min(5, $remaining));
        $output = '';
        try {
            $process->run(function (string $type, string $chunk) use (&$output): void {
                if (strlen($output) + strlen($chunk) > 65536) {
                    throw new RuntimeException('DNS response exceeded the verification output limit.');
                }
                $output .= $chunk;
            });
        } catch (\Throwable $exception) {
            $process->stop(0);
            throw new RuntimeException('Bounded DNS tools failed; install bind-tools and check DNS egress.', previous: $exception);
        }
        if (! $process->isSuccessful()) {
            throw new RuntimeException('DNS tools failed; install bind-tools and check DNS egress.');
        }

        return $output;
    }
}
