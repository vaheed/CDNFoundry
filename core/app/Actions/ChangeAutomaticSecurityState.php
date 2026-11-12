<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\DomainEdgeCell;
use App\Models\EdgeTask;
use App\Support\Notifications\EventPublisher;
use App\Support\SecurityConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moves a domain to a new automatic security state without touching its configuration revision. The change travels to the
 * edges that serve the domain as a small overlay task with its own increasing sequence, and is recorded in the audit log.
 */
class ChangeAutomaticSecurityState
{
    public const STATES = ['normal', 'suspected', 'restricted', 'recovering'];

    /** @param array<string, mixed> $context */
    public static function execute(Domain $domain, string $state, string $cause, array $context = []): void
    {
        abort_unless(in_array($state, self::STATES, true), 500, 'Unknown automatic security state.');
        DB::transaction(function () use ($domain, $state, $cause, $context): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $previous = $locked->automatic_security_state;
            if ($previous === $state) {
                return;
            }
            $locked->update([
                'automatic_security_state' => $state, 'security_state_changed_at' => now(),
                'security_overlay_sequence' => $locked->security_overlay_sequence + 1,
            ]);
            self::dispatchOverlay($locked);
            EventPublisher::publish('security.state_changed', $locked, "Security state of {$locked->name} changed to {$state}", "It was {$previous}. Cause: {$cause}.");
            AuditLog::record(null, 'security.automatic_state_changed', $locked, [
                'from' => $previous, 'to' => $state, 'cause' => $cause, 'overlay_sequence' => $locked->security_overlay_sequence, ...$context,
            ]);
            $domain->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** Sends the domain's current overlay to every edge that serves it, or is receiving it. */
    public static function dispatchOverlay(Domain $domain): void
    {
        $edgeIds = DomainEdgeCell::query()->where('domain_id', $domain->id)
            ->where(fn ($query) => $query->whereNotNull('active_cell_id')->orWhereNotNull('target_cell_id'))
            ->distinct()->pluck('edge_id');
        $overlay = SecurityConfig::overlay($domain);
        foreach ($edgeIds as $edgeId) {
            // A pending overlay for the same domain is obsolete the moment a newer one exists.
            EdgeTask::query()->where('edge_id', $edgeId)->where('type', 'security_overlay')->where('status', 'pending')
                ->where('payload->domain_id', $domain->id)->delete();
            EdgeTask::query()->create([
                'id' => (string) Str::uuid(), 'edge_id' => $edgeId, 'type' => 'security_overlay', 'status' => 'pending',
                'payload' => [
                    'domain_id' => $domain->id, 'domain' => $domain->name, 'overlay_sequence' => (int) $domain->security_overlay_sequence,
                    'state' => $overlay['state'], 'limits' => $overlay['limits'],
                ],
            ]);
        }
    }
}
