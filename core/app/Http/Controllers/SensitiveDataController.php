<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Support\SensitiveDataCheckData;

class SensitiveDataController extends DetectionSettingsController
{
    protected function column(): string
    {
        return 'sensitive_data_check';
    }

    protected function auditName(): string
    {
        return 'security.sensitive_data_check';
    }

    protected function validated(array $input): array
    {
        return SensitiveDataCheckData::validate($input);
    }

    protected function payload(Domain $domain): array
    {
        return ['sensitive_data_check' => $domain->sensitive_data_check, 'kinds' => SensitiveDataCheckData::KINDS];
    }
}
