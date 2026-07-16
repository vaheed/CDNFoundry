<?php

namespace App\Enums;

enum DomainLifecycleState: string
{
    case PendingVerification = 'pending_verification';
    case Active = 'active';
    case Disabled = 'disabled';
    case Deprovisioning = 'deprovisioning';
}
