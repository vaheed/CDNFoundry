<?php

namespace App\Support;

use App\Models\ControlNode;
use App\Models\ControlPlaneState;
use App\Models\Edge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The optional two-node control plane. One node is active and the other is a warm standby; a person
 * promotes the standby. Everything here is read from PostgreSQL, so a node that cannot reach the
 * writable primary serves nothing.
 */
final class ControlPlane
{
    public const STATE_HEADER = 'X-CDNF-Control-State';

    public const EPOCH_HEADER = 'X-CDNF-Control-Epoch';

    private static ?array $fence = null;

    /** PostgreSQL in recovery is a replica: it cannot take writes, so this node must not serve. */
    public static function databaseInRecovery(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        return (bool) DB::selectOne('select pg_is_in_recovery() as recovery')->recovery;
    }

    public static function epoch(): int
    {
        return ControlPlaneState::current()->epoch;
    }

    /** @return list<string> ordered control addresses, at most two */
    public static function addresses(): array
    {
        return array_values(ControlPlaneState::current()->addresses ?? []);
    }

    /** The signed document every edge receives. Equal lists have equal checksums. */
    public static function addressDocument(): array
    {
        return ['addresses' => self::addresses()];
    }

    /** @param  list<string>  $addresses */
    public static function validAddresses(array $addresses): bool
    {
        return count($addresses) <= 2 && count($addresses) === count(array_unique($addresses))
            && collect($addresses)->every(fn (mixed $address): bool => is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false);
    }

    /**
     * The epochs other nodes publish. Each peer is reached by its address with the normal control
     * hostname, so TLS is verified exactly as for a user. An unreachable peer says nothing.
     *
     * @return list<int>
     */
    public static function peerEpochs(): array
    {
        $epochs = [];
        $url = rtrim((string) config('app.url'), '/').'/control-state';
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?: (parse_url($url, PHP_URL_SCHEME) === 'http' ? 80 : 443);
        foreach ((array) config('edge.control_peer_addresses') as $address) {
            try {
                $resolve = $host.':'.$port.':'.(str_contains($address, ':') ? "[{$address}]" : $address);
                $body = Http::connectTimeout(2)->timeout(3)->acceptJson()->withOptions(['curl' => [CURLOPT_RESOLVE => [$resolve]]])->get($url)->throw()->json();
                if (is_int($body['epoch'] ?? null)) {
                    $epochs[] = $body['epoch'];
                }
            } catch (Throwable) {
            }
        }

        return $epochs;
    }

    /**
     * Why this node must not run application services, or null when it may. The checks are
     * the writable primary, the node being the one recorded as active, and no peer
     * having seen a higher epoch. A fresh single-node installation has no recorded active node.
     */
    public static function fencedReason(bool $consultPeers = false): ?string
    {
        if (self::databaseInRecovery()) {
            return 'database_in_recovery';
        }
        // `demote` makes the primary read-only for a planned switchover.
        if (DB::getDriverName() === 'pgsql' && DB::selectOne('show default_transaction_read_only')->default_transaction_read_only === 'on') {
            return 'database_read_only';
        }
        $state = ControlPlaneState::current();
        $name = (string) config('edge.control_node_name');
        if ($name !== '' && $state->active_node !== null && $state->active_node !== $name) {
            return 'not_active_node';
        }
        if ($consultPeers && collect(self::peerEpochs())->contains(fn (int $epoch): bool => $epoch > $state->epoch)) {
            return 'superseded_by_higher_epoch';
        }

        return null;
    }

    /** The scheduler gate: cheap, memoized for a few seconds, and checked against peers every minute. */
    public static function mayRun(): bool
    {
        $now = time();
        if (self::$fence === null || $now - self::$fence['at'] >= 5) {
            $peersDue = self::$fence === null || $now - self::$fence['peers_at'] >= 60;
            try {
                $reason = self::fencedReason($peersDue);
            } catch (Throwable) {
                $reason = 'state_unreadable';
            }
            self::$fence = ['at' => $now, 'peers_at' => $peersDue ? $now : self::$fence['peers_at'], 'reason' => $reason];
        }

        return self::$fence['reason'] === null;
    }

    public static function forgetFence(): void
    {
        self::$fence = null;
    }

    /**
     * `none` on a single node, `ready` when a standby is replicating within the bound, holds the same
     * secrets, and every enabled edge holds the current address list; `degraded` otherwise.
     *
     * @return array{ha: string, reasons: list<string>, epoch: int, replication_lag_seconds: ?int, edges_missing_addresses: int}
     */
    public static function status(): array
    {
        $state = ControlPlaneState::current();
        $standby = ControlNode::query()->where('role', 'standby')->first();
        $active = ControlNode::query()->where('role', 'active')->first();
        $missing = self::edgesMissingAddresses();
        if ($standby === null) {
            return ['ha' => 'none', 'reasons' => [], 'epoch' => $state->epoch, 'replication_lag_seconds' => null, 'edges_missing_addresses' => $missing];
        }
        $reasons = [];
        if ($standby->reported_at === null || $standby->reported_at->lt(now()->subMinutes(3))) {
            $reasons[] = 'standby_unreachable';
        }
        $lag = $standby->replication_lag_seconds;
        if ($lag === null || $lag > (int) config('edge.control_replication_lag_alert_seconds')) {
            $reasons[] = 'replication_lag';
        }
        if ($standby->valkey_linked !== true) {
            $reasons[] = 'valkey_replication_broken';
        }
        if ($active === null || ($active->fingerprints ?? []) === [] || self::sorted($active->fingerprints) !== self::sorted($standby->fingerprints ?? [])) {
            $reasons[] = 'secret_fingerprint_mismatch';
        }
        if ($missing > 0) {
            $reasons[] = 'edges_missing_control_addresses';
        }
        if (count($state->addresses ?? []) < 2) {
            $reasons[] = 'addresses_not_published';
        }

        return ['ha' => $reasons === [] ? 'ready' : 'degraded', 'reasons' => $reasons, 'epoch' => $state->epoch, 'replication_lag_seconds' => $lag, 'edges_missing_addresses' => $missing];
    }

    /** @param  array<string, mixed>  $values */
    private static function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }

    /** Enabled, registered edges whose last heartbeat did not report the current address list. */
    public static function edgesMissingAddresses(): int
    {
        $checksum = self::addressChecksum();

        return Edge::query()->where('enabled', true)->whereNotNull('registered_at')
            ->get(['capacity'])->filter(fn (Edge $edge): bool => ($edge->capacity['control_addresses_checksum'] ?? null) !== $checksum)->count();
    }

    public static function addressChecksum(): string
    {
        return hash('sha256', ArtifactSigner::encode(self::addressDocument()));
    }
}
