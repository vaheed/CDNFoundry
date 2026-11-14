<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;

/**
 * A domain's opt-in to client-side security. The cell adds a report-only Content-Security-Policy to HTML responses, which makes
 * browsers report every script and connection to a reserved path. Reports are bounded per domain and cell, and never change a response.
 */
final class ClientSecurityData
{
    public const REPORT_PATH = '/.cdnf/csp-report';

    /** Most scripts and connections remembered per domain; the rest are counted as dropped, never stored. */
    public const MAXIMUM_SIGHTINGS = 1000;

    public const DEFAULT_REPORTS_PER_MINUTE = 120;

    public const DEFAULT_BASELINE_MINUTES = 60;

    /** The policy only reports; it blocks nothing. */
    public const POLICY = "script-src 'none'; connect-src 'none'; report-uri ".self::REPORT_PATH;

    /** @return array{maximum_reports_per_minute: int, baseline_minutes: int, enabled_at: string} */
    public static function validate(array $input): array
    {
        $data = Validator::make($input, [
            'maximum_reports_per_minute' => ['sometimes', 'integer', 'between:10,600'],
            'baseline_minutes' => ['sometimes', 'integer', 'between:0,1440'],
        ])->validate();

        return [
            'maximum_reports_per_minute' => (int) ($data['maximum_reports_per_minute'] ?? self::DEFAULT_REPORTS_PER_MINUTE),
            'baseline_minutes' => (int) ($data['baseline_minutes'] ?? self::DEFAULT_BASELINE_MINUTES),
            'enabled_at' => now()->toIso8601String(),
        ];
    }

    public static function forEdge(?array $stored): array
    {
        if (! is_array($stored)) {
            return ['enabled' => false];
        }

        return ['enabled' => true, 'report_path' => self::REPORT_PATH, 'policy' => self::POLICY, 'maximum_reports_per_minute' => (int) $stored['maximum_reports_per_minute'], 'report_bytes' => 4096];
    }
}
