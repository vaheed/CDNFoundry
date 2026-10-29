<?php

namespace App\Support;

use App\Models\DnsCluster;
use App\Models\TlsOrder;
use Symfony\Component\Process\Process;
use Throwable;

class AcmeDnsPropagation
{
    public function visible(TlsOrder $order): bool
    {
        $override = config('services.acme.dns_probe_server');
        $servers = is_string($override) && $override !== ''
            ? [$override]
            : DnsCluster::query()->where('enabled', true)->orderBy('id')->get()
                ->flatMap(fn (DnsCluster $cluster) => collect($cluster->nameservers)->map(
                    fn ($item) => is_array($item) ? ($item['hostname'] ?? '') : $item
                ))->unique()->values()->all();
        if ($servers === [] || count($servers) > 8 || $order->challenges->count() > 4) {
            return false;
        }

        $deadline = microtime(true) + 35;
        foreach ($servers as $server) {
            if (! is_string($server) || ! preg_match('/^[a-z0-9.:-]{1,253}$/i', $server)) {
                return false;
            }
            foreach ($order->challenges as $challenge) {
                if (microtime(true) >= $deadline) {
                    return false;
                }
                if ($challenge->cleaned_at !== null || $challenge->expires_at->isPast()) {
                    return false;
                }
                try {
                    $process = new Process(['dig', '@'.$server, $challenge->record_name.'.', 'TXT', '+norecurse', '+time=1', '+tries=1', '+noall', '+comments', '+answer'], timeout: 2);
                    $process->run();
                    $answer = $process->isSuccessful() && strlen($process->getOutput()) <= 8192
                        && self::hasAnswer($process->getOutput(), $challenge->record_name, $challenge->record_value);
                } catch (Throwable) {
                    $answer = false;
                }
                if (! $answer) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function hasAnswer(string $response, string $name, string $value): bool
    {
        if (! preg_match('/status: NOERROR,/', $response)
            || ! preg_match('/;; flags: [^;]*\baa\b[^;]*;/', $response)
            || preg_match('/;; flags: [^;]*\btc\b[^;]*;/', $response)) {
            return false;
        }
        foreach (explode("\n", $response) as $line) {
            if (preg_match('/^'.preg_quote(rtrim($name, '.').'.', '/').'\s+\d+\s+IN\s+TXT\s+"([^"]+)"\s*$/i', trim($line), $match)
                && hash_equals($value, $match[1])) {
                return true;
            }
        }

        return false;
    }
}
