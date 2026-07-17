<?php

namespace App\Support;

use RuntimeException;

class NameserverResolver
{
    /** @return list<string> */
    public function resolve(string $domain): array
    {
        if (! function_exists('pcntl_alarm')) {
            throw new RuntimeException('Bounded DNS resolution is unavailable.');
        }

        $previousAsync = pcntl_async_signals(true);
        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        pcntl_signal(SIGALRM, fn () => throw new RuntimeException('Nameserver lookup timed out.'));
        pcntl_alarm(5);
        try {
            $records = dns_get_record($domain, DNS_NS);
            if ($records === false) {
                throw new RuntimeException('Nameserver lookup failed.');
            }

            return collect($records)->pluck('target')->filter()->map(fn (string $name): string => mb_strtolower(rtrim($name, '.')))->unique()->sort()->values()->all();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsync);
        }
    }
}
