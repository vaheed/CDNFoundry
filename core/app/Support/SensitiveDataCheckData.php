<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Which kinds of sensitive data the cell looks for in the start of text responses. Detection only logs a security event with the kind;
 * the matched value never leaves the cell.
 */
final class SensitiveDataCheckData
{
    public const KINDS = [
        'card' => 'Payment card numbers (with the Luhn check)',
        'iban' => 'IBAN bank account numbers',
        'private_key' => 'Private keys',
        'api_key' => 'Common API key formats',
    ];

    /** Only this many bytes from the start of a response are read. */
    public const RESPONSE_BYTES = 16384;

    /** @return array{kinds: list<string>} */
    public static function validate(array $input): array
    {
        $data = Validator::make($input, [
            'kinds' => ['required', 'array', 'min:1', 'max:'.count(self::KINDS)],
            'kinds.*' => ['required', 'string', 'distinct', Rule::in(array_keys(self::KINDS))],
        ])->validate();
        $kinds = array_values(array_intersect(array_keys(self::KINDS), $data['kinds']));

        return ['kinds' => $kinds];
    }

    public static function forEdge(?array $stored): array
    {
        return ['kinds' => is_array($stored) ? array_values($stored['kinds'] ?? []) : [], 'response_bytes' => self::RESPONSE_BYTES];
    }
}
