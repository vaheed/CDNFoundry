<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class GeoDnsConfig
{
    public const MAX_TARGETS_PER_SET = 8;
    public const MAX_COUNTRIES = 64;
    public const MAX_CONTINENTS = 7;
    public const CONTINENTS = ['AF', 'AN', 'AS', 'EU', 'NA', 'OC', 'SA'];

    /** @return array{default:list<string>,countries:array<string,list<string>>,continents:array<string,list<string>>} */
    public static function validate(array $input, string $type): array
    {
        $validator = Validator::make($input, [
            'default' => ['required', 'array', 'min:1', 'max:'.self::MAX_TARGETS_PER_SET],
            'default.*' => ['required', 'string', 'max:45'],
            'countries' => ['sometimes', 'array', 'max:'.self::MAX_COUNTRIES],
            'countries.*' => ['required', 'array', 'min:1', 'max:'.self::MAX_TARGETS_PER_SET],
            'countries.*.*' => ['required', 'string', 'max:45'],
            'continents' => ['sometimes', 'array', 'max:'.self::MAX_CONTINENTS],
            'continents.*' => ['required', 'array', 'min:1', 'max:'.self::MAX_TARGETS_PER_SET],
            'continents.*.*' => ['required', 'string', 'max:45'],
        ]);
        $validator->after(function ($validator) use ($input, $type): void {
            foreach (array_keys((array) ($input['countries'] ?? [])) as $code) {
                if (preg_match('/^[A-Z]{2}$/', (string) $code) !== 1 || in_array($code, ['ZZ', 'XX'], true)) {
                    $validator->errors()->add("countries.$code", 'Country keys must be uppercase ISO 3166-1 alpha-2 codes.');
                }
            }
            foreach (array_keys((array) ($input['continents'] ?? [])) as $code) {
                if (! in_array($code, self::CONTINENTS, true)) {
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
                        $flag = $type === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
                        if (filter_var($target, FILTER_VALIDATE_IP, $flag) === false) {
                            $validator->errors()->add("$group.$code", "Targets must match the $type record family.");
                        }
                    }
                }
            }
        });
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $normalize = fn (array $targets): array => array_values(array_map(
            fn (string $target): string => $type === 'AAAA' ? strtolower($target) : $target,
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
