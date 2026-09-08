<?php

namespace App\Support;

use RuntimeException;

final class PublicSuffix
{
    /** @var array<string, true>|null */
    private static ?array $rules = null;

    public static function isSuffix(string $name): bool
    {
        if (self::$rules === null) {
            $lines = file(resource_path('data/public_suffix_list.dat'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                throw new RuntimeException('The packaged Public Suffix List is unavailable.');
            }
            $rules = [];
            foreach ($lines as $line) {
                if (str_starts_with($line, '//')) {
                    continue;
                }
                $prefix = str_starts_with($line, '!') ? '!' : (str_starts_with($line, '*.') ? '*.' : '');
                $ascii = idn_to_ascii(substr($line, strlen($prefix)), IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                if ($ascii === false) {
                    throw new RuntimeException('The packaged Public Suffix List contains an invalid rule.');
                }
                $rules[$prefix.$ascii] = true;
            }
            self::$rules = $rules;
        }
        // Exceptions take precedence over exact and wildcard rules. Both the
        // ICANN and PRIVATE sections define tenant boundaries.
        if (isset(self::$rules['!'.$name])) {
            return false;
        }
        $parent = substr($name, (strpos($name, '.') ?: strlen($name)) + 1);

        return isset(self::$rules[$name]) || isset(self::$rules['*.'.$parent]);
    }
}
