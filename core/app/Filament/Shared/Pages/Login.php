<?php

namespace App\Filament\Shared\Pages;

use Filament\Auth\Pages\Login as FilamentLogin;
use Filament\Schemas\Components\Component;

/**
 * The stock sign-in form keeps typed values in the browser until a field fires an input event. A password
 * manager, a browser autofill, or a paste can fill a field without one, so the server receives an empty
 * password and answers "The password field is required" although the field shows a value. Both fields
 * are read from the page just before the form is submitted.
 */
class Login extends FilamentLogin
{
    private const SYNC_BEFORE_SUBMIT = "\$el.form?.addEventListener('submit', () => \$el.dispatchEvent(new Event('input', { bubbles: true })), true)";

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()->extraInputAttributes(['x-init' => self::SYNC_BEFORE_SUBMIT]);
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->extraInputAttributes(['x-init' => self::SYNC_BEFORE_SUBMIT]);
    }
}
