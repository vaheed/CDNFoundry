<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use App\Support\IncidentProfile;
use App\Support\Notifications\EventPublisher;
use Illuminate\Support\Facades\DB;

/**
 * Turns on the incident profile for one domain for a chosen time. The expiry is stored with the profile, so it ends with no operator
 * action. The overlay goes out once on start (one sequence) and once on end.
 */
class StartIncidentProfile
{
    public static function execute(Domain $domain, ?User $actor, int $minutes, ?string $ipAddress = null): Domain
    {
        abort_unless($minutes >= IncidentProfile::MINIMUM_MINUTES && $minutes <= IncidentProfile::MAXIMUM_MINUTES, 422, 'The duration must be between '.IncidentProfile::MINIMUM_MINUTES.' and '.IncidentProfile::MAXIMUM_MINUTES.' minutes.');

        return DB::transaction(function () use ($domain, $actor, $minutes, $ipAddress): Domain {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            abort_if(IncidentProfile::active($locked), 409, 'The incident profile is already on for this domain.');
            abort_unless($locked->lifecycle_state->value === 'active', 409, 'Only an active domain can use the incident profile.');
            $locked->forceFill(['incident_started_at' => now(), 'incident_expires_at' => now()->addMinutes($minutes), 'incident_started_by' => $actor?->getKey()])->save();
            // The profile tightens limits through the existing restricted state, and sends the rest of its protections in the same overlay.
            ChangeAutomaticSecurityState::execute($locked, 'restricted', 'incident_started', ['minutes' => $minutes, 'actor_id' => $actor?->getKey()], force: true);
            AuditLog::record($actor, 'security.incident_started', $locked, [
                'minutes' => $minutes, 'expires_at' => $locked->incident_expires_at->toIso8601String(), 'overlay_sequence' => $locked->security_overlay_sequence,
            ], $ipAddress);
            EventPublisher::publish('incident.started', $locked, "Incident profile started for {$locked->name}", "Protections stay on until {$locked->incident_expires_at->toDateTimeString()} UTC.");

            return $locked;
        });
    }
}
