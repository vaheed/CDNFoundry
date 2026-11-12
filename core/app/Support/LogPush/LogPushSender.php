<?php

namespace App\Support\LogPush;

use App\Models\LogPushDestination;
use App\Support\Notifications\OutboundDestination;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Delivers one gzip NDJSON batch. Every connection resolves the destination again, refuses an unsafe address, and connects only to
 * the address that passed, so a changed DNS answer cannot redirect a push to an internal service.
 */
final class LogPushSender
{
    /** @return int bytes sent */
    public function send(LogPushDestination $destination, string $dataset, string $gzipped, string $firstEventId): int
    {
        $secrets = $destination->secrets();

        return $destination->kind === 's3'
            ? $this->s3($secrets, $destination, $dataset, $gzipped, $firstEventId)
            : $this->https($secrets, $dataset, $gzipped);
    }

    /** @param array<string, string> $secrets */
    private function https(array $secrets, string $dataset, string $gzipped): int
    {
        $target = OutboundDestination::webhookTarget($secrets['url']);
        if (! str_starts_with(strtolower($secrets['url']), 'https://')) {
            throw new RuntimeException('The destination must use https://.');
        }
        $headers = ['Content-Encoding' => 'gzip', 'X-CDNFoundry-Dataset' => $dataset];
        if (($secrets['auth'] ?? 'hmac') === 'bearer') {
            $headers['Authorization'] = 'Bearer '.$secrets['bearer_token'];
        } else {
            $headers['X-CDNFoundry-Signature'] = 'sha256='.hash_hmac('sha256', $gzipped, $secrets['signing_secret']);
        }
        $response = $this->pinned($this->client()->withBody($gzipped, 'application/x-ndjson')->withHeaders($headers), $target)->post($target['url']);
        if (! $response->successful()) {
            throw new RuntimeException('The destination answered HTTP '.$response->status().'.');
        }

        return strlen($gzipped);
    }

    /** @param array<string, string> $secrets */
    private function s3(array $secrets, LogPushDestination $destination, string $dataset, string $gzipped, string $firstEventId): int
    {
        $target = OutboundDestination::webhookTarget($secrets['endpoint']);
        $now = gmdate('Ymd\THis\Z');
        $day = substr($now, 0, 8);
        $key = ltrim(($secrets['prefix'] !== '' ? $secrets['prefix'].'/' : '').$destination->domain_id.'/'.$dataset.'/'.substr($day, 0, 4).'/'.substr($day, 4, 2).'/'.substr($day, 6, 2).'/'.$now.'-'.substr($firstEventId, 0, 8).'.ndjson.gz', '/');
        $path = '/'.$secrets['bucket'].'/'.implode('/', array_map('rawurlencode', explode('/', $key)));
        $host = parse_url($secrets['endpoint'], PHP_URL_HOST).(parse_url($secrets['endpoint'], PHP_URL_PORT) ? ':'.parse_url($secrets['endpoint'], PHP_URL_PORT) : '');
        $payloadHash = hash('sha256', $gzipped);
        $canonical = "PUT\n{$path}\n\nhost:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$now}\n\nhost;x-amz-content-sha256;x-amz-date\n{$payloadHash}";
        $scope = "{$day}/{$secrets['region']}/s3/aws4_request";
        $toSign = "AWS4-HMAC-SHA256\n{$now}\n{$scope}\n".hash('sha256', $canonical);
        $signingKey = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $secrets['region'], hash_hmac('sha256', $day, 'AWS4'.$secrets['secret_key'], true), true), true), true);
        $authorization = "AWS4-HMAC-SHA256 Credential={$secrets['access_key']}/{$scope}, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=".hash_hmac('sha256', $toSign, $signingKey);
        $request = $this->client()->withBody($gzipped, 'application/gzip')->withHeaders([
            'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $now, 'Authorization' => $authorization, 'Content-Encoding' => 'gzip',
        ]);
        $url = rtrim($secrets['endpoint'], '/').$path;
        $response = $this->pinned($request, $target)->put($url);
        if (! $response->successful()) {
            throw new RuntimeException('The object store answered HTTP '.$response->status().'.');
        }

        return strlen($gzipped);
    }

    private function client(): PendingRequest
    {
        $request = Http::connectTimeout(5)->timeout(20)->withoutRedirecting()->withUserAgent('CDNFoundry-LogPush');
        $ca = config('services.log_push.test_ca_file');

        return is_string($ca) && $ca !== '' && ! app()->environment('production') ? $request->withOptions(['verify' => $ca]) : $request;
    }

    /** @param array{host: string, port: int, address: string, url: string} $target */
    private function pinned(PendingRequest $request, array $target): PendingRequest
    {
        if (filter_var($target['host'], FILTER_VALIDATE_IP)) {
            return $request;
        }
        $address = str_contains($target['address'], ':') ? "[{$target['address']}]" : $target['address'];

        return $request->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$address}"]]]);
    }
}
