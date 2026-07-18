<?php

namespace App\Support;

final class PlatformDnsConfirmation
{
    public static function issue(array $settings): string
    {
        $timestamp = now()->timestamp;
        $hash = hash('sha256', json_encode(self::canonicalize($settings), JSON_THROW_ON_ERROR));
        $payload = $timestamp.'.'.$hash;

        return $payload.'.'.hash_hmac('sha256', $payload, self::key());
    }

    public static function valid(?string $token, array $settings): bool
    {
        if (! is_string($token) || preg_match('/^(\d{10})\.([a-f0-9]{64})\.([a-f0-9]{64})$/', $token, $matches) !== 1) {
            return false;
        }
        $timestamp = (int) $matches[1];
        if ($timestamp < now()->subMinutes(15)->timestamp || $timestamp > now()->addMinute()->timestamp) {
            return false;
        }
        $payload = $matches[1].'.'.$matches[2];
        if (! hash_equals(hash_hmac('sha256', $payload, self::key()), $matches[3])) {
            return false;
        }
        $expected = hash('sha256', json_encode(self::canonicalize($settings), JSON_THROW_ON_ERROR));

        return hash_equals($expected, $matches[2]);
    }

    private static function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }

        return $value;
    }

    private static function key(): string
    {
        return (string) config('app.key');
    }
}
