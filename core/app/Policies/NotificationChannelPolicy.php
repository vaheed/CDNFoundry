<?php

namespace App\Policies;

use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\PlatformSettings;

/** A platform channel belongs to administrators; a user channel belongs to its owner, while the administrator switch allows it. */
class NotificationChannelPolicy
{
    public function manage(User $user, NotificationChannel $channel): bool
    {
        if ($user->isDisabled()) {
            return false;
        }

        return $channel->isPlatform() ? $user->isAdmin() : $channel->user_id === $user->getKey();
    }

    /** Whether the account may add channels of its own. */
    public function createOwn(User $user): bool
    {
        return ! $user->isDisabled() && ! $user->isAdmin() && app(PlatformSettings::class)->get('notifications', 'domain_user_channels') === true;
    }
}
