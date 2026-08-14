<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DomainLifecycleState;
use App\Http\Controllers\Controller;
use App\Http\Resources\DomainResource;
use App\Models\Domain;
use App\Support\DomainNameserverVerification;
use Illuminate\Http\Request;

class DomainVerificationController extends Controller
{
    public function __invoke(Request $request, Domain $domain, DomainNameserverVerification $verification): DomainResource
    {
        abort_if($domain->lifecycle_state === DomainLifecycleState::Deprovisioning, 409, 'A deprovisioning domain cannot be verified.');
        if ($domain->nameservers_verified_at === null) {
            $activated = $verification->complete($domain, $request->user(), ipAddress: $request->ip(), forced: true);
            if ($activated) {
                $verification->dispatchActivation($domain->id, $request->user()->getKey());
            }
        }

        return DomainResource::make($domain->refresh());
    }
}
