<?php

namespace App\Support;

/** The rule engine and rule set commits every cell image is pinned to; the same file is checked against the image build in tests/contract. */
final class RulesetPins
{
    public const MATCHING = 'pinned';

    public const DIFFERENT = 'different';

    public const UNREPORTED = 'unreported';

    /** @return array{crs: string, modsecurity: string} */
    public static function pinned(): array
    {
        static $pins = null;

        return $pins ??= json_decode((string) file_get_contents(resource_path('contracts/runtime-pins.json')), true, 4, JSON_THROW_ON_ERROR);
    }

    /** Whether the commits a cell reported equal the pinned ones. A cell that reports none is unreported, never matching. */
    public static function state(mixed $reported): string
    {
        if (! is_array($reported) || ! is_string($reported['crs'] ?? null) || ! is_string($reported['modsecurity'] ?? null)) {
            return self::UNREPORTED;
        }
        $pins = self::pinned();

        return hash_equals($pins['crs'], $reported['crs']) && hash_equals($pins['modsecurity'], $reported['modsecurity']) ? self::MATCHING : self::DIFFERENT;
    }
}
