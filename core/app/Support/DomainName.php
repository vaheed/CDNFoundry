<?php

namespace App\Support;

use InvalidArgumentException;

final class DomainName
{
    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        // UTS #46 maps Unicode dot variants; remove at most one root dot.
        $value = str_replace(['。', '．', '｡'], '.', $value);
        if (str_ends_with($value, '.')) {
            $value = substr($value, 0, -1);
        }
        $ascii = idn_to_ascii($value, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false || strlen($ascii) > 253 || ! str_contains($ascii, '.')) {
            throw new InvalidArgumentException('Enter a valid registrable domain name.');
        }
        foreach (explode('.', $ascii) as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/^(?!-)[a-z0-9-]+(?<!-)$/', $label) !== 1) {
                throw new InvalidArgumentException('Enter a valid registrable domain name.');
            }
        }
        if (PublicSuffix::isSuffix($ascii)) {
            throw new InvalidArgumentException('A public suffix cannot be managed as a domain.');
        }

        return $ascii;
    }
}
