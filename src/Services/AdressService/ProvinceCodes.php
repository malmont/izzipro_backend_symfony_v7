<?php

namespace App\Services\AdressService;

/**
 * Code de province ou d'État à partir d'un nom saisi (« Québec » → QC, « Ontario » → ON, « New York » → NY), pour une
 * adresse gardée telle quelle quand le site n'a pas de vérification EasyPost (31/10/2026). Un nom inconnu est rendu
 * tel quel : rien n'est jamais refusé ici.
 */
final class ProvinceCodes
{
    private const CANADA = [
        'AB' => ['alberta'],
        'BC' => ['britishcolumbia', 'colombiebritannique'],
        'MB' => ['manitoba'],
        'NB' => ['newbrunswick', 'nouveaubrunswick'],
        'NL' => ['newfoundlandandlabrador', 'newfoundland', 'terreneuveetlabrador', 'terreneuve'],
        'NS' => ['novascotia', 'nouvelleecosse'],
        'NT' => ['northwestterritories', 'territoiresdunordouest'],
        'NU' => ['nunavut'],
        'ON' => ['ontario'],
        'PE' => ['princeedwardisland', 'ileduprinceedouard'],
        'QC' => ['quebec'],
        'SK' => ['saskatchewan'],
        'YT' => ['yukon'],
    ];

    private const USA = [
        'AL' => ['alabama'], 'AK' => ['alaska'], 'AZ' => ['arizona'], 'AR' => ['arkansas'], 'CA' => ['california', 'californie'],
        'CO' => ['colorado'], 'CT' => ['connecticut'], 'DE' => ['delaware'], 'DC' => ['districtofcolumbia', 'washingtondc'],
        'FL' => ['florida', 'floride'], 'GA' => ['georgia', 'georgie'], 'HI' => ['hawaii'], 'ID' => ['idaho'], 'IL' => ['illinois'],
        'IN' => ['indiana'], 'IA' => ['iowa'], 'KS' => ['kansas'], 'KY' => ['kentucky'], 'LA' => ['louisiana', 'louisiane'],
        'ME' => ['maine'], 'MD' => ['maryland'], 'MA' => ['massachusetts'], 'MI' => ['michigan'], 'MN' => ['minnesota'],
        'MS' => ['mississippi'], 'MO' => ['missouri'], 'MT' => ['montana'], 'NE' => ['nebraska'], 'NV' => ['nevada'],
        'NH' => ['newhampshire'], 'NJ' => ['newjersey'], 'NM' => ['newmexico', 'nouveaumexique'], 'NY' => ['newyork'],
        'NC' => ['northcarolina', 'carolinedunord'], 'ND' => ['northdakota', 'dakotadunord'], 'OH' => ['ohio'], 'OK' => ['oklahoma'],
        'OR' => ['oregon'], 'PA' => ['pennsylvania', 'pennsylvanie'], 'RI' => ['rhodeisland'], 'SC' => ['southcarolina', 'carolinedusud'],
        'SD' => ['southdakota', 'dakotadusud'], 'TN' => ['tennessee'], 'TX' => ['texas'], 'UT' => ['utah'], 'VT' => ['vermont'],
        'VA' => ['virginia', 'virginie'], 'WA' => ['washington'], 'WV' => ['westvirginia', 'virginieoccidentale'],
        'WI' => ['wisconsin'], 'WY' => ['wyoming'],
    ];

    /** Pays reconnu : CA / US (code ou nom), autre ou vide = inconnu */
    public static function normalize(string $province, string $country = ''): string
    {
        $province = trim($province);
        if ($province === '') {
            return $province;
        }
        $countryKey = self::key($country);
        $isCanada = in_array($countryKey, ['ca', 'can', 'canada'], true);
        $isUsa = in_array($countryKey, ['us', 'usa', 'unitedstates', 'unitedstatesofamerica', 'etatsunis', 'etatsunisdamerique'], true);
        $tables = $isCanada ? [self::CANADA] : ($isUsa ? [self::USA] : [self::CANADA, self::USA]);

        $key = self::key($province);
        foreach ($tables as $table) {
            if (strlen($key) === 2 && isset($table[strtoupper($key)])) {
                return strtoupper($key);
            }
            foreach ($table as $code => $names) {
                if (in_array($key, $names, true)) {
                    return $code;
                }
            }
        }

        return $province;
    }

    /** Minuscules, sans accent, sans espace ni ponctuation */
    private static function key(string $value): string
    {
        // Accents retirés par décomposition Unicode (intl), indépendante de la locale du processus
        $ascii = class_exists(\Normalizer::class)
            ? (string) preg_replace('/\p{Mn}/u', '', (string) \Normalizer::normalize($value, \Normalizer::FORM_D))
            : (string) (@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value);

        return strtolower((string) preg_replace('/[^a-z]/i', '', $ascii));
    }
}
