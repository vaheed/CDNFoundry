<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** A domain's alert for mitigated requests: how many in how many minutes. The period cannot exceed what the slots keep. */
final class SecurityAlertData
{
    public const PERIODS = [5 => '5 minutes', 10 => '10 minutes', 15 => '15 minutes'];

    /** One alert per domain in this many minutes, however long the attack lasts. */
    public const COOLDOWN_MINUTES = 60;

    /** Reasons a rule or the platform enforces. A request only logged by a rule is not a mitigation. */
    public const NOT_MITIGATIONS = ['custom_rule_log', 'platform_rule_log', 'waf_rule_logged', 'sensitive_data_card', 'sensitive_data_iban', 'sensitive_data_private_key', 'sensitive_data_api_key'];

    public static function validate(array $input): array
    {
        $data = Validator::make($input, [
            'threshold' => ['required', 'integer', 'between:1,1000000'],
            'period_minutes' => ['required', 'integer', Rule::in(array_keys(self::PERIODS))],
        ])->validate();

        return ['threshold' => (int) $data['threshold'], 'period_minutes' => (int) $data['period_minutes']];
    }
}
