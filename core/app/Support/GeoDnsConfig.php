<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class GeoDnsConfig
{
    public const SUPPORTED_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'PTR'];

    public const MAX_TARGETS_PER_SET = 8;

    public const MAX_COUNTRIES = 64;

    public const MAX_CONTINENTS = 7;

    public const CONTINENTS = GeoVocabulary::CONTINENTS;

    /** @return list<string> */
    public static function countryCodes(): array
    {
        return GeoVocabulary::countries();
    }

    /** @return array{default:list<string>,countries:array<string,list<string>>,continents:array<string,list<string>>} */
    public static function validate(array $input, string $type, string $zone): array
    {
        if (! in_array($type, self::SUPPORTED_TYPES, true)) {
            throw ValidationException::withMessages(['type' => "PowerDNS Geo-DNS runtime does not support $type answers."]);
        }

        $validator = Validator::make($input, [
            'default' => ['required', 'array', 'min:1', 'max:'.self::MAX_TARGETS_PER_SET],
            'default.*' => ['required', 'string', 'max:4096'],
            'countries' => ['sometimes', 'array', 'max:'.self::MAX_COUNTRIES],
            'countries.*' => ['required', 'array', 'min:1', 'max:'.self::MAX_TARGETS_PER_SET],
            'countries.*.*' => ['required', 'string', 'max:4096'],
            'continents' => ['sometimes', 'array', 'max:'.self::MAX_CONTINENTS],
            'continents.*' => ['required', 'array', 'min:1', 'max:'.self::MAX_TARGETS_PER_SET],
            'continents.*.*' => ['required', 'string', 'max:4096'],
        ]);
        $validator->after(function ($validator) use ($input, $type, $zone): void {
            foreach (array_keys((array) ($input['countries'] ?? [])) as $code) {
                if (! GeoVocabulary::isCountry($code) || strtoupper($code) !== $code) {
                    $validator->errors()->add("countries.$code", 'Country keys must be uppercase ISO 3166-1 alpha-2 codes.');
                }
            }
            foreach (array_keys((array) ($input['continents'] ?? [])) as $code) {
                if (! GeoVocabulary::isContinent($code) || strtoupper($code) !== $code) {
                    $validator->errors()->add("continents.$code", 'The continent code is unsupported.');
                }
            }
            foreach (['default', 'countries', 'continents'] as $group) {
                $sets = $group === 'default' ? ['default' => $input[$group] ?? []] : (array) ($input[$group] ?? []);
                foreach ($sets as $code => $targets) {
                    if (count($targets) !== count(array_unique(array_map('strtolower', $targets)))) {
                        $validator->errors()->add("$group.$code", 'A target set cannot contain duplicates.');
                    }
                    foreach ($targets as $target) {
                        try {
                            DnsRecordData::normalizeContent($type, $target, $zone);
                        } catch (\InvalidArgumentException $exception) {
                            $validator->errors()->add("$group.$code", $exception->getMessage());
                        }
                    }
                }
            }
        });
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $normalize = fn (array $targets): array => array_values(array_map(
            fn (string $target): string => DnsRecordData::normalizeContent($type, $target, $zone),
            $targets,
        ));
        $countries = collect($input['countries'] ?? [])->map($normalize)->sortKeys()->all();
        $continents = collect($input['continents'] ?? [])->map($normalize)->sortKeys()->all();

        return ['default' => $normalize($input['default']), 'countries' => $countries, 'continents' => $continents];
    }

    public static function select(array $config, ?string $country, ?string $continent): array
    {
        $country = strtoupper((string) $country);
        $continent = strtoupper((string) $continent);

        return $config['countries'][$country] ?? $config['continents'][$continent] ?? $config['default'];
    }
}
