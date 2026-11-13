<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Support\CredentialCheckData;

class CredentialCheckController extends DetectionSettingsController
{
    protected function column(): string
    {
        return 'credential_check';
    }

    protected function auditName(): string
    {
        return 'security.credential_check';
    }

    protected function validated(array $input): array
    {
        return CredentialCheckData::validate($input);
    }

    protected function payload(Domain $domain): array
    {
        return ['credential_check' => $domain->credential_check, 'formats' => array_keys(CredentialCheckData::FORMATS), 'maximum_login_paths' => CredentialCheckData::MAXIMUM_LOGIN_PATHS];
    }
}
