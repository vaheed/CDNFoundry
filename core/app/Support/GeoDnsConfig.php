<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class GeoDnsConfig
{
    public const SUPPORTED_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'PTR'];

    private const COUNTRY_CODES = 'AD,AE,AF,AG,AI,AL,AM,AO,AQ,AR,AS,AT,AU,AW,AX,AZ,BA,BB,BD,BE,BF,BG,BH,BI,BJ,BL,BM,BN,BO,BQ,BR,BS,BT,BV,BW,BY,BZ,CA,CC,CD,CF,CG,CH,CI,CK,CL,CM,CN,CO,CR,CU,CV,CW,CX,CY,CZ,DE,DJ,DK,DM,DO,DZ,EC,EE,EG,EH,ER,ES,ET,FI,FJ,FK,FM,FO,FR,GA,GB,GD,GE,GF,GG,GH,GI,GL,GM,GN,GP,GQ,GR,GS,GT,GU,GW,GY,HK,HM,HN,HR,HT,HU,ID,IE,IL,IM,IN,IO,IQ,IR,IS,IT,JE,JM,JO,JP,KE,KG,KH,KI,KM,KN,KP,KR,KW,KY,KZ,LA,LB,LC,LI,LK,LR,LS,LT,LU,LV,LY,MA,MC,MD,ME,MF,MG,MH,MK,ML,MM,MN,MO,MP,MQ,MR,MS,MT,MU,MV,MW,MX,MY,MZ,NA,NC,NE,NF,NG,NI,NL,NO,NP,NR,NU,NZ,OM,PA,PE,PF,PG,PH,PK,PL,PM,PN,PR,PS,PT,PW,PY,QA,RE,RO,RS,RU,RW,SA,SB,SC,SD,SE,SG,SH,SI,SJ,SK,SL,SM,SN,SO,SR,SS,ST,SV,SX,SY,SZ,TC,TD,TF,TG,TH,TJ,TK,TL,TM,TN,TO,TR,TT,TV,TW,TZ,UA,UG,UM,US,UY,UZ,VA,VC,VE,VG,VI,VN,VU,WF,WS,YE,YT,ZA,ZM,ZW';

    public const MAX_TARGETS_PER_SET = 8;

    public const MAX_COUNTRIES = 64;

    public const MAX_CONTINENTS = 7;

    public const CONTINENTS = ['AF', 'AN', 'AS', 'EU', 'NA', 'OC', 'SA'];

    /** @return list<string> */
    public static function countryCodes(): array
    {
        return explode(',', self::COUNTRY_CODES);
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
                if (! in_array($code, explode(',', self::COUNTRY_CODES), true)) {
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
