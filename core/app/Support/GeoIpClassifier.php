<?php

namespace App\Support;

final class GeoIpClassifier
{
    /** @return array{country:?string,continent:?string,source:string} */
    public function classify(string $ip): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return ['country' => null, 'continent' => null, 'source' => 'unknown'];
        }
        $database = (string) config('services.geoip.database', '/mmdb/GeoLite2-City.mmdb');
        if (! is_file($database) || ! is_readable($database)) {
            return ['country' => null, 'continent' => null, 'source' => 'unknown'];
        }

        return [
            'country' => $this->lookup($database, $ip, ['country', 'iso_code']),
            'continent' => $this->lookup($database, $ip, ['continent', 'code']),
            'source' => 'mmdb',
        ];
    }

    private function lookup(string $database, string $ip, array $path): ?string
    {
        $command = 'mmdblookup --file '.escapeshellarg($database).' --ip '.escapeshellarg($ip).' '.implode(' ', array_map('escapeshellarg', $path)).' 2>/dev/null';
        $output = shell_exec($command);
        if (! is_string($output) || preg_match('/"([A-Za-z]{2})"/', $output, $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[1]);
    }
}
