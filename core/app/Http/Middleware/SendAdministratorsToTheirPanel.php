<?php

namespace App\Http\Middleware;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;

/**
 * The customer panel for an administrator who is already signed in: instead of the framework's bare 403 page, the
 * administrator is sent to the administrator panel, which answers only from the management networks. Domain users
 * and guests behave exactly as with the standard panel authentication.
 */
class SendAdministratorsToTheirPanel extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $user = Filament::auth()->user();
        if ($user instanceof User && $user->isAdmin() && ! $user->isDisabled()) {
            abort(redirect()->to(Filament::getPanel('admin')->getUrl()));
        }

        parent::authenticate($request, $guards);
    }
}
