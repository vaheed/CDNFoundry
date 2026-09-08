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
        abort_unless(in_array($domain->lifecycle_state, [DomainLifecycleState::PendingVerification, DomainLifecycleState::Active], true), 409, 'Only pending or active domains can be verified.');
        abort_if($domain->nameservers_verified_at === null && $domain->claim_expires_at?->isPast(), 409, 'This pending claim has expired.');
        if ($domain->nameservers_verified_at === null) {
            $activated = $verification->complete($domain, $request->user(), ipAddress: $request->ip(), forced: true);
            if ($activated) {
                $verification->dispatchActivation($domain->id, $request->user()->getKey());
            }
        }

        return DomainResource::make($domain->refresh());
    }
}
