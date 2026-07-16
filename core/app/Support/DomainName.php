<?php

namespace App\Support;

use InvalidArgumentException;

final class DomainName
{
    /** @var list<string> */
    private const PUBLIC_SUFFIXES = ['com', 'net', 'org', 'edu', 'gov', 'io', 'ir', 'uk', 'co.uk', 'com.au', 'de', 'fr', 'jp'];

    public static function normalize(string $value): string
    {
        $value = mb_strtolower(rtrim(trim($value), '.'));
        $ascii = idn_to_ascii($value, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false || strlen($ascii) > 253 || ! str_contains($ascii, '.')) {
            throw new InvalidArgumentException('Enter a valid registrable domain name.');
        }
        foreach (explode('.', $ascii) as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/^(?!-)[a-z0-9-]+(?<!-)$/', $label) !== 1) {
                throw new InvalidArgumentException('Enter a valid registrable domain name.');
            }
        }
        if (in_array($ascii, self::PUBLIC_SUFFIXES, true)) {
            throw new InvalidArgumentException('A public suffix cannot be managed as a domain.');
        }

        return $ascii;
    }
}
