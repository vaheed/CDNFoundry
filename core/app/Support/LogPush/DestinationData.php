<?php

namespace App\Support\LogPush;

use App\Models\Domain;
use App\Models\LogPushDestination;
use App\Support\Notifications\OutboundDestination;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Validates a log push destination once for the API and the panel and builds its encrypted settings. */
final class DestinationData
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{kind: string, name: string, datasets: list<string>, secrets: array<string, string>, hint: string, truncate: bool, signing_secret: ?string}
     */
    public static function validate(array $input): array
    {
        $v = Validator::make($input, [
            'kind' => ['required', Rule::in(LogPushDestination::KINDS)],
            'name' => ['required', 'string', 'max:100'],
            'datasets' => ['required', 'array', 'min:1', 'max:3'],
            'datasets.*' => ['string', 'distinct', Rule::in(array_keys(LogDatasets::ALL))],
            'truncate_client_ip' => ['sometimes', 'boolean'],
            'url' => ['required_if:kind,https', 'nullable', 'string', 'max:2048'],
            'auth' => ['sometimes', 'nullable', Rule::in(['bearer', 'hmac'])],
            'bearer_token' => ['required_if:auth,bearer', 'nullable', 'string', 'min:8', 'max:512'],
            'endpoint' => ['required_if:kind,s3', 'nullable', 'string', 'max:2048'],
            'bucket' => ['required_if:kind,s3', 'nullable', 'string', 'regex:/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/'],
            'region' => ['required_if:kind,s3', 'nullable', 'string', 'regex:/^[a-z0-9-]{2,32}$/'],
            'prefix' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._\/-]*$/'],
            'access_key' => ['required_if:kind,s3', 'nullable', 'string', 'min:8', 'max:128'],
            'secret_key' => ['required_if:kind,s3', 'nullable', 'string', 'min:8', 'max:256'],
        ])->validate();

        $signing = null;
        try {
            if ($v['kind'] === 'https') {
                OutboundDestination::webhookTarget($v['url']);
                if (! str_starts_with(strtolower($v['url']), 'https://')) {
                    throw new RuntimeException('The URL must use https://.');
                }
                $auth = $v['auth'] ?? 'hmac';
                $secrets = ['url' => $v['url'], 'auth' => $auth];
                if ($auth === 'bearer') {
                    $secrets['bearer_token'] = $v['bearer_token'];
                } else {
                    $signing = Str::random(48);
                    $secrets['signing_secret'] = $signing;
                }
                $host = parse_url($v['url'], PHP_URL_HOST);
                $hint = "HTTPS to {$host}";
            } else {
                OutboundDestination::webhookTarget($v['endpoint']);
                $secrets = ['endpoint' => rtrim($v['endpoint'], '/'), 'bucket' => $v['bucket'], 'region' => $v['region'], 'prefix' => trim((string) ($v['prefix'] ?? ''), '/'),
                    'access_key' => $v['access_key'], 'secret_key' => $v['secret_key']];
                $hint = "S3 bucket {$v['bucket']} on ".parse_url($v['endpoint'], PHP_URL_HOST);
            }
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([$v['kind'] === 'https' ? 'url' : 'endpoint' => $exception->getMessage()]);
        }

        return ['kind' => $v['kind'], 'name' => $v['name'], 'datasets' => array_values($v['datasets']), 'secrets' => $secrets, 'hint' => $hint,
            'truncate' => (bool) ($v['truncate_client_ip'] ?? false), 'signing_secret' => $signing];
    }

    /** Checks the per-domain and platform bounds. Run it inside the transaction that creates the destination, after locking the domain. */
    public static function assertRoom(Domain $domain): void
    {
        $settings = app(PlatformSettings::class);
        $perDomain = $settings->integer('log_push', 'max_destinations_per_domain');
        if (LogPushDestination::query()->where('domain_id', $domain->id)->count() >= $perDomain) {
            throw ValidationException::withMessages(['name' => "A domain can have at most {$perDomain} log push destinations."]);
        }
        if (LogPushDestination::query()->count() >= $settings->integer('log_push', 'max_destinations')) {
            throw ValidationException::withMessages(['name' => 'The platform limit for log push destinations is reached. Ask an administrator.']);
        }
    }
}
