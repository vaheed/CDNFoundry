<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DomainLifecycleState;
use App\Http\Controllers\Controller;
use App\Http\Resources\DomainResource;
use App\Models\AuditLog;
use App\Models\Domain;
use Illuminate\Http\Request;

class DomainVerificationController extends Controller
{
    public function __invoke(Request $request, Domain $domain): DomainResource
    {
        abort_if($domain->lifecycle_state === DomainLifecycleState::Deprovisioning, 409, 'A deprovisioning domain cannot be verified.');
        if ($domain->nameservers_verified_at === null) {
            $domain->forceFill(['nameservers_verified_at' => now(), 'nameservers_verified_by' => $request->user()->getKey()])->save();
            AuditLog::record($request->user(), 'domain.nameservers_force_verified', $domain, ['name' => $domain->name], $request->ip());
        }

        return DomainResource::make($domain->refresh());
    }
}
