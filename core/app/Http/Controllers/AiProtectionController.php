<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Support\AiProtectionData;

class AiProtectionController extends DetectionSettingsController
{
    protected function column(): string
    {
        return 'ai_protection';
    }

    protected function auditName(): string
    {
        return 'security.ai_protection';
    }

    protected function validated(array $input): array
    {
        return AiProtectionData::validate($input);
    }

    protected function payload(Domain $domain): array
    {
        return ['ai_protection' => $domain->ai_protection, 'maximum_endpoints' => AiProtectionData::MAXIMUM_ENDPOINTS];
    }
}
