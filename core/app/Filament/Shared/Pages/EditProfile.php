<?php

namespace App\Filament\Shared\Pages;

use App\Models\AuditLog;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;

class EditProfile extends BaseEditProfile
{
    private bool $passwordChanging = false;

    protected function beforeSave(): void
    {
        $this->passwordChanging = filled($this->data['password'] ?? null);
    }

    protected function afterSave(): void
    {
        $user = $this->getUser();
        if ($this->passwordChanging) {
            $user->tokens()->delete();
        }
        AuditLog::record(
            $user,
            $this->passwordChanging ? 'profile.password_changed' : 'profile.updated',
            $user,
            [],
            request()->ip(),
        );
    }
}
