<?php

namespace App\Support;

use App\Enums\DomainLifecycleState;
use App\Models\Domain;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Publishes the zones this platform serves to ClickHouse, so DNS telemetry is rolled up by served zone
 * and domain instead of by every name that was asked. A snapshot is written only when the list changed
 * and at least every twelve hours, because ClickHouse expires a snapshot after two days.
 */
final class ServedZonePublisher
{
    private const BATCH = 5000;

    private const REFRESH_SECONDS = 12 * 3600;

    private const CACHE_KEY = 'served-zone-publisher';

    /** @return int the number of zones published, or 0 when nothing needed publishing */
    public function publish(bool $force = false): int
    {
        $configuration = config('services.clickhouse');
        if (blank($configuration['zones_password'] ?? null)) {
            return 0;
        }
        // Competing claims can share one name; the zone belongs to the active holder, else the oldest claim.
        $zones = Domain::query()
            ->whereIn('lifecycle_state', [DomainLifecycleState::PendingVerification->value, DomainLifecycleState::Active->value])
            ->orderByRaw('CASE WHEN lifecycle_state = ? THEN 0 ELSE 1 END', [DomainLifecycleState::Active->value])->orderBy('id')
            ->get(['id', 'name'])->unique(fn (Domain $domain): string => strtolower($domain->name))
            ->sortBy('id')->pluck('name', 'id');
        $fingerprint = hash('sha256', $zones->map(fn (string $name, int $id): string => "$id:$name")->implode("\n"));
        $previous = Cache::get(self::CACHE_KEY);
        if (! $force && is_array($previous) && $previous['fingerprint'] === $fingerprint && $previous['at'] > time() - self::REFRESH_SECONDS) {
            return 0;
        }
        // Every row of a snapshot carries the same generation, and the newest generation is the one in force.
        $generation = max(time(), (int) ($previous['generation'] ?? 0) + 1);
        foreach ($zones->chunk(self::BATCH) as $chunk) {
            $body = $chunk->map(fn (string $name, int $id): string => json_encode(
                ['generation' => $generation, 'zone' => strtolower($name), 'domain_id' => $id], JSON_THROW_ON_ERROR,
            ))->implode("\n");
            $response = Http::baseUrl(rtrim($configuration['url'], '/'))
                ->withBasicAuth($configuration['zones_username'], $configuration['zones_password'])
                ->connectTimeout(2)->timeout(20)->withBody($body, 'application/x-ndjson')
                ->post('/?'.http_build_query(['database' => $configuration['database'], 'query' => 'INSERT INTO served_zones (generation, zone, domain_id) FORMAT JSONEachRow']));
            if ($response->failed()) {
                throw new RuntimeException('ClickHouse refused the served-zone snapshot: '.mb_substr($response->body(), 0, 300));
            }
        }
        Cache::forever(self::CACHE_KEY, ['fingerprint' => $fingerprint, 'at' => time(), 'generation' => $generation]);

        return $zones->count();
    }
}
