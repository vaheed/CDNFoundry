<?php

namespace App\Support;

final class GeoVocabulary
{
    private const COUNTRIES = 'AD,AE,AF,AG,AI,AL,AM,AO,AQ,AR,AS,AT,AU,AW,AX,AZ,BA,BB,BD,BE,BF,BG,BH,BI,BJ,BL,BM,BN,BO,BQ,BR,BS,BT,BV,BW,BY,BZ,CA,CC,CD,CF,CG,CH,CI,CK,CL,CM,CN,CO,CR,CU,CV,CW,CX,CY,CZ,DE,DJ,DK,DM,DO,DZ,EC,EE,EG,EH,ER,ES,ET,FI,FJ,FK,FM,FO,FR,GA,GB,GD,GE,GF,GG,GH,GI,GL,GM,GN,GP,GQ,GR,GS,GT,GU,GW,GY,HK,HM,HN,HR,HT,HU,ID,IE,IL,IM,IN,IO,IQ,IR,IS,IT,JE,JM,JO,JP,KE,KG,KH,KI,KM,KN,KP,KR,KW,KY,KZ,LA,LB,LC,LI,LK,LR,LS,LT,LU,LV,LY,MA,MC,MD,ME,MF,MG,MH,MK,ML,MM,MN,MO,MP,MQ,MR,MS,MT,MU,MV,MW,MX,MY,MZ,NA,NC,NE,NF,NG,NI,NL,NO,NP,NR,NU,NZ,OM,PA,PE,PF,PG,PH,PK,PL,PM,PN,PR,PS,PT,PW,PY,QA,RE,RO,RS,RU,RW,SA,SB,SC,SD,SE,SG,SH,SI,SJ,SK,SL,SM,SN,SO,SR,SS,ST,SV,SX,SY,SZ,TC,TD,TF,TG,TH,TJ,TK,TL,TM,TN,TO,TR,TT,TV,TW,TZ,UA,UG,UM,US,UY,UZ,VA,VC,VE,VG,VI,VN,VU,WF,WS,YE,YT,ZA,ZM,ZW';

    public const CONTINENTS = ['AF', 'AN', 'AS', 'EU', 'NA', 'OC', 'SA'];

    /** @return list<string> */
    public static function countries(): array
    {
        return explode(',', self::COUNTRIES);
    }

    private const CONTINENT_NAMES = ['AF' => 'Africa', 'AN' => 'Antarctica', 'AS' => 'Asia', 'EU' => 'Europe', 'NA' => 'North America', 'OC' => 'Oceania', 'SA' => 'South America'];

    /** @return array<string, string> code => "Name (CODE)", sorted by name, for form selects */
    public static function countryOptions(): array
    {
        $options = [];
        foreach (self::countries() as $code) {
            $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-'.$code, 'en') : $code;
            $options[$code] = ($name !== '' && $name !== $code ? $name : $code).' ('.$code.')';
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /** @return array<string, string> code => "Name (CODE)", for form selects */
    public static function continentOptions(): array
    {
        return array_map(fn (string $code): string => self::CONTINENT_NAMES[$code].' ('.$code.')', array_combine(self::CONTINENTS, self::CONTINENTS));
    }

    public static function countryName(?string $code): string
    {
        $code = strtoupper((string) $code);
        if (! self::isCountry($code)) {
            return 'Unknown';
        }
        $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-'.$code, 'en') : '';

        return $name !== '' && $name !== $code ? $name : $code;
    }

    public static function continentName(?string $code): string
    {
        return self::CONTINENT_NAMES[strtoupper((string) $code)] ?? 'Unknown';
    }

    public static function isCountry(?string $code): bool
    {
        return is_string($code) && in_array(strtoupper($code), self::countries(), true);
    }

    public static function isContinent(?string $code): bool
    {
        return is_string($code) && in_array(strtoupper($code), self::CONTINENTS, true);
    }

    /** @return array{country:string,continent:string} */
    public static function normalize(?string $country, ?string $continent): array
    {
        $country = strtoupper((string) $country);
        $continent = strtoupper((string) $continent);

        return [
            'country' => self::isCountry($country) ? $country : 'unknown',
            'continent' => self::isContinent($continent) ? $continent : 'unknown',
        ];
    }
}
