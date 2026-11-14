<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A domain's leaked-credential check: which login paths carry a password and where the password sits in the request body.
 * The cell hashes the submitted password and looks it up in a fixed-size set shipped in the release; the result sets the
 * `leaked_password` rule field. Nothing here blocks by itself.
 */
final class CredentialCheckData
{
    public const FORMATS = ['form' => 'Form fields (application/x-www-form-urlencoded)', 'json' => 'JSON object (application/json)'];

    public const MAXIMUM_LOGIN_PATHS = 5;

    /** Only the start of a body is read, so a login request cannot make the cell buffer more than this. */
    public const BODY_BYTES = 8192;

    private const FIELD_NAME = '/^[A-Za-z0-9_.\-\[\]]{1,64}$/';

    /** @return array{login_paths: list<array{path: string, format: string, username_field: string|null, password_field: string}>} */
    public static function validate(array $input): array
    {
        $data = Validator::make($input, [
            'login_paths' => ['required', 'array', 'min:1', 'max:'.self::MAXIMUM_LOGIN_PATHS],
            'login_paths.*' => ['required', 'array'],
            'login_paths.*.path' => ['required', 'string', 'max:200'],
            'login_paths.*.format' => ['required', Rule::in(array_keys(self::FORMATS))],
            'login_paths.*.username_field' => ['nullable', 'string', 'regex:'.self::FIELD_NAME],
            'login_paths.*.password_field' => ['required', 'string', 'regex:'.self::FIELD_NAME],
        ], [
            'login_paths.*.username_field.regex' => 'A field name uses letters, digits, and _ . - [ ] only, up to 64 characters.',
            'login_paths.*.password_field.regex' => 'A field name uses letters, digits, and _ . - [ ] only, up to 64 characters.',
        ])->validate();

        $paths = [];
        foreach (array_values($data['login_paths']) as $index => $row) {
            $path = $row['path'];
            if (! str_starts_with($path, '/') || str_contains($path, '?') || str_contains($path, '#') || preg_match('/[\x00-\x20\x7f]/', $path)) {
                throw ValidationException::withMessages(["login_paths.$index.path" => 'The path must start with / and carry no query string, fragment, or spaces.']);
            }
            if (isset($paths[$path])) {
                throw ValidationException::withMessages(["login_paths.$index.path" => 'This path is already listed.']);
            }
            $paths[$path] = ['path' => $path, 'format' => $row['format'], 'username_field' => $row['username_field'] ?? null, 'password_field' => $row['password_field']];
        }
        ksort($paths, SORT_STRING);

        return ['login_paths' => array_values($paths)];
    }

    /** What the cell receives: the validated paths, or an empty list when the check is off. */
    public static function forEdge(?array $stored): array
    {
        return ['login_paths' => is_array($stored) ? array_values($stored['login_paths'] ?? []) : [], 'body_bytes' => self::BODY_BYTES];
    }
}
