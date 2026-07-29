<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class RuntimeVersions
{
    public const COMPONENTS = ['gateway', 'agent', 'normal_cell', 'waf_cell'];

    public static function validate(array $versions): array
    {
        if (array_keys($versions) !== self::COMPONENTS) {
            throw ValidationException::withMessages(['versions' => 'All four fixed runtime components are required in canonical order.']);
        }
        foreach ($versions as $component => $reference) {
            if (! is_string($reference) || strlen($reference) > 255
                || ! preg_match('/^[a-z0-9][a-z0-9._\/-]*@sha256:[a-f0-9]{64}$/', $reference)) {
                throw ValidationException::withMessages(["versions.$component" => 'Runtime images must use an immutable registry digest.']);
            }
        }

        return $versions;
    }
}
