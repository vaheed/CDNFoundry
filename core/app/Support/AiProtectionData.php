<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A domain's LLM endpoints and the JSON field that holds the prompt. The cell scores the prompt with fixed patterns for known injection
 * phrases and personal data. It is pattern based and does not understand a prompt.
 */
final class AiProtectionData
{
    public const MAXIMUM_ENDPOINTS = 5;

    /** Only this many bytes of a request body are read. */
    public const BODY_BYTES = 16384;

    /** @return array{endpoints: list<string>, prompt_field: string} */
    public static function validate(array $input): array
    {
        $data = Validator::make($input, [
            'endpoints' => ['required', 'array', 'min:1', 'max:'.self::MAXIMUM_ENDPOINTS],
            'endpoints.*' => ['required', 'string', 'max:200'],
            'prompt_field' => ['required', 'string', 'regex:/^[A-Za-z0-9_\-]{1,64}(\.[A-Za-z0-9_\-]{1,64}){0,3}$/'],
        ], ['prompt_field.regex' => 'Use a JSON field name, or up to four names joined by dots, such as messages or input.prompt.'])->validate();
        foreach (array_values($data['endpoints']) as $index => $path) {
            if (! str_starts_with($path, '/') || str_contains($path, '?') || str_contains($path, '#') || preg_match('/[\x00-\x20\x7f]/', $path)) {
                throw ValidationException::withMessages(["endpoints.$index" => 'The path must start with / and carry no query string, fragment, or spaces.']);
            }
        }
        $endpoints = array_values(array_unique($data['endpoints']));
        sort($endpoints, SORT_STRING);

        return ['endpoints' => $endpoints, 'prompt_field' => $data['prompt_field']];
    }

    public static function forEdge(?array $stored): array
    {
        return ['endpoints' => is_array($stored) ? array_values($stored['endpoints'] ?? []) : [], 'prompt_field' => is_array($stored) ? (string) ($stored['prompt_field'] ?? '') : '', 'body_bytes' => self::BODY_BYTES];
    }
}
