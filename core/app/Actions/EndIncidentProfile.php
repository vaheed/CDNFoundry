<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use App\Support\Notifications\EventPublisher;
use Illuminate\Support\Facades\DB;

/** Turns the incident profile off, early by a person or at its expiry, and returns the domain's automatic state to normal. */
class EndIncidentProfile
{
    /** @param 'manual'|'expired' $reason */
    public static function execute(Domain $domain, ?User $actor, string $reason, ?string $ipAddress = null): bool
    {
        return DB::transaction(function () use ($domain, $actor, $reason, $ipAddress): bool {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            if ($locked->incident_expires_at === null) {
                return false;
            }
            $startedAt = $locked->incident_started_at;
            $locked->forceFill(['incident_started_at' => null, 'incident_expires_at' => null, 'incident_started_by' => null])->save();
            ChangeAutomaticSecurityState::execute($locked, 'normal', 'incident_ended', ['reason' => $reason, 'actor_id' => $actor?->getKey()], force: true);
            AuditLog::record($actor, 'security.incident_ended', $locked, [
                'reason' => $reason, 'started_at' => $startedAt?->toIso8601String(), 'overlay_sequence' => $locked->security_overlay_sequence,
            ], $ipAddress);
            EventPublisher::publish('incident.ended', $locked, "Incident profile ended for {$locked->name}", $reason === 'expired' ? 'It expired.' : 'It was ended early.');

            return true;
        });
    }
}
