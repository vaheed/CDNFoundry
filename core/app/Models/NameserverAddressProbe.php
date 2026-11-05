<?php

namespace App\Models;

use App\Support\PlatformSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class NameserverAddressProbe extends Model
{
    /** An answer stays good for this long; after that the address must answer again before it can be saved again. */
    public const ANSWER_VALID_HOURS = 24;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['requested_at' => 'immutable_datetime', 'answered_at' => 'immutable_datetime'];
    }

    public static function canonical(string $address): string
    {
        $packed = @inet_pton($address);

        return $packed === false ? strtolower($address) : inet_ntop($packed);
    }

    public static function isAnswering(string $address): bool
    {
        return self::query()->where('address', self::canonical($address))->where('status', 'answering')
            ->where('answered_at', '>=', now()->subHours(self::ANSWER_VALID_HOURS))->exists();
    }

    /**
     * Ask a few fresh edges to query the address for the zone's SOA. Returns false when no edge can.
     * An address with open tasks is not asked again until they finish.
     */
    public static function request(string $address, string $zone): bool
    {
        $address = self::canonical($address);
        $probe = self::query()->updateOrCreate(['address' => $address], ['zone' => $zone, 'status' => 'pending', 'requested_at' => now(), 'last_error' => null]);
        if (EdgeTask::query()->where('type', 'dns_probe')->whereIn('status', ['pending', 'delivered'])->where('payload->address', $address)->exists()) {
            return true;
        }
        $edges = Edge::query()->where('enabled', true)->whereNull('identity_revoked_at')->whereNotNull('registered_at')
            ->where('last_heartbeat_at', '>=', now()->subSeconds(app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds')))
            ->orderBy('id')->limit(3)->get();
        foreach ($edges as $edge) {
            EdgeTask::query()->create(['id' => (string) Str::uuid(), 'edge_id' => $edge->id, 'type' => 'dns_probe', 'status' => 'pending',
                'payload' => ['address' => $address, 'zone' => $zone, 'probe_id' => $probe->id]]);
        }
        if ($edges->isEmpty()) {
            $probe->update(['status' => 'failed', 'last_error' => 'no_edge_available']);
        }

        return $edges->isNotEmpty();
    }

    /** Fold one finished task into the probe: any answer wins; it fails only once every task has failed. */
    public static function recordResult(EdgeTask $task): void
    {
        $address = $task->payload['address'] ?? null;
        $probe = is_string($address) ? self::query()->where('address', $address)->first() : null;
        if ($probe === null) {
            return;
        }
        if ($task->status === 'succeeded') {
            $probe->update(['status' => 'answering', 'answered_at' => now(), 'observer_edge_id' => $task->edge_id, 'last_error' => null]);

            return;
        }
        $open = EdgeTask::query()->where('type', 'dns_probe')->where('payload->probe_id', $probe->id)->whereIn('status', ['pending', 'delivered'])->exists();
        if (! $open && $probe->status !== 'answering') {
            $probe->update(['status' => 'failed', 'last_error' => mb_substr((string) ($task->last_error ?? 'no_answer'), 0, 120)]);
        }
    }
}
