<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\ControlNode;
use App\Models\ControlPlaneState;
use App\Support\ControlPlane;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The database side of the Fleet `control` commands. Each action is one transaction, so the
 * state a node reads is never half changed.
 */
class ControlManage extends Command
{
    protected $signature = 'cdnf:control:manage
        {action : addresses|record|promote|remove-standby}
        {--addresses= : Comma separated ordered control addresses (at most two)}
        {--name= : Node name}
        {--role= : active|standby}
        {--address= : Node address}
        {--fingerprints= : JSON object of secret fingerprints}
        {--lag= : Replication lag in seconds}
        {--valkey-linked= : 1 or 0}';

    protected $description = 'Change control plane high availability state (used by cdnfoundry-fleet)';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'addresses' => $this->addresses(),
            'record' => $this->record(),
            'promote' => $this->promote(),
            'remove-standby' => $this->removeStandby(),
            default => $this->fail('Unknown action.'),
        };
    }

    private function addresses(): int
    {
        $addresses = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('addresses')))));
        if (! ControlPlane::validAddresses($addresses)) {
            $this->error('Provide at most two distinct IP addresses.');

            return self::FAILURE;
        }
        ControlPlaneState::current()->update(['addresses' => $addresses]);
        AuditLog::record(null, 'control.addresses_changed', null, ['addresses' => $addresses]);
        $this->line(ControlPlane::addressChecksum());

        return self::SUCCESS;
    }

    private function record(): int
    {
        $role = (string) $this->option('role');
        $name = (string) $this->option('name');
        $fingerprints = $this->option('fingerprints') ? json_decode((string) $this->option('fingerprints'), true) : null;
        if (! in_array($role, ['active', 'standby'], true) || ! preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $name)
            || filter_var($this->option('address'), FILTER_VALIDATE_IP) === false || ($this->option('fingerprints') && ! is_array($fingerprints))) {
            $this->error('A valid --name, --role, --address, and JSON --fingerprints are required.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($role, $name, $fingerprints): void {
            // A node changes role by being recorded under the new one; the other holder of that role is replaced.
            ControlNode::query()->where('role', $role)->where('name', '!=', $name)->delete();
            if ($role === 'active') {
                ControlPlaneState::current()->update(['active_node' => $name]);
            }
            ControlNode::query()->updateOrCreate(['name' => $name], [
                'role' => $role, 'address' => (string) $this->option('address'),
                ...($fingerprints !== null ? ['fingerprints' => $fingerprints] : []),
                ...($this->option('lag') !== null ? ['replication_lag_seconds' => max(0, (int) $this->option('lag'))] : []),
                ...($this->option('valkey-linked') !== null ? ['valkey_linked' => (bool) (int) $this->option('valkey-linked')] : []),
                'reported_at' => now(),
            ]);
        });

        return self::SUCCESS;
    }

    /** Runs on the promoted node's PostgreSQL: the epoch grows by one and the node becomes the recorded active node. */
    private function promote(): int
    {
        $name = (string) $this->option('name');
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $name)) {
            $this->error('--name is required.');

            return self::FAILURE;
        }
        $epoch = DB::transaction(function () use ($name): int {
            $state = ControlPlaneState::query()->lockForUpdate()->findOrFail(1);
            // A promotion that was interrupted and run again must not count twice.
            if ($state->active_node !== $name) {
                $state->update(['epoch' => $state->epoch + 1, 'active_node' => $name]);
            }
            // The old active node leaves the table: it is not a standby until `rejoin` rebuilds it.
            ControlNode::query()->where('role', 'active')->where('name', '!=', $name)->delete();
            ControlNode::query()->where('name', $name)->update(['role' => 'active', 'replication_lag_seconds' => null]);

            return $state->epoch;
        });
        AuditLog::record(null, 'control.promoted', null, ['node' => $name, 'epoch' => $epoch]);
        ControlPlane::forgetFence();
        $this->line((string) $epoch);

        return self::SUCCESS;
    }

    private function removeStandby(): int
    {
        DB::transaction(function (): void {
            ControlNode::query()->where('role', 'standby')->delete();
            $active = ControlNode::query()->where('role', 'active')->first();
            ControlPlaneState::current()->update(['addresses' => $active === null ? [] : [$active->address]]);
        });
        AuditLog::record(null, 'control.standby_removed', null, []);

        return self::SUCCESS;
    }
}
