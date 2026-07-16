<?php

namespace App\Policies;

use App\Models\Domain;
use App\Models\User;

class DomainPolicy
{
    public function view(User $user, Domain $domain): bool
    {
        return $user->isAdmin() || $domain->users()->whereKey($user->getKey())->exists();
    }

    public function update(User $user, Domain $domain): bool
    {
        return $this->view($user, $domain);
    }

    public function delete(User $user, Domain $domain): bool
    {
        return $this->view($user, $domain);
    }
}
