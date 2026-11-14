<?php

namespace App\LossPrevention\Services;

use Symfony\Component\Intl\Countries;

/**
 * Noms à afficher du pays et de la région d'une boutique, dans la langue demandée (réglages de la prévention des pertes : l'écran montrait
 * « CA » et « QC »). Le pays vient des noms de pays de Symfony ; la région, d'une table tenue ici pour le Canada et les États-Unis (les seuls
 * pays dont la région décide de quelque chose dans le module). Un code inconnu rend null : l'écran garde alors le code.
 */
final class LossPlaceLabels
{
    /** Code de province ou de territoire du Canada => [français, anglais] */
    private const CANADA = [
        'AB' => ['Alberta', 'Alberta'], 'BC' => ['Colombie-Britannique', 'British Columbia'], 'MB' => ['Manitoba', 'Manitoba'],
        'NB' => ['Nouveau-Brunswick', 'New Brunswick'], 'NL' => ['Terre-Neuve-et-Labrador', 'Newfoundland and Labrador'], 'NS' => ['Nouvelle-Écosse', 'Nova Scotia'],
        'NT' => ['Territoires du Nord-Ouest', 'Northwest Territories'], 'NU' => ['Nunavut', 'Nunavut'], 'ON' => ['Ontario', 'Ontario'],
        'PE' => ['Île-du-Prince-Édouard', 'Prince Edward Island'], 'QC' => ['Québec', 'Quebec'], 'SK' => ['Saskatchewan', 'Saskatchewan'], 'YT' => ['Yukon', 'Yukon'],
    ];

    /** Code d'État des États-Unis => [français, anglais] */
    private const UNITED_STATES = [
        'AL' => ['Alabama', 'Alabama'], 'AK' => ['Alaska', 'Alaska'], 'AZ' => ['Arizona', 'Arizona'], 'AR' => ['Arkansas', 'Arkansas'], 'CA' => ['Californie', 'California'],
        'CO' => ['Colorado', 'Colorado'], 'CT' => ['Connecticut', 'Connecticut'], 'DE' => ['Delaware', 'Delaware'], 'DC' => ['District de Columbia', 'District of Columbia'],
        'FL' => ['Floride', 'Florida'], 'GA' => ['Géorgie', 'Georgia'], 'HI' => ['Hawaï', 'Hawaii'], 'ID' => ['Idaho', 'Idaho'], 'IL' => ['Illinois', 'Illinois'],
        'IN' => ['Indiana', 'Indiana'], 'IA' => ['Iowa', 'Iowa'], 'KS' => ['Kansas', 'Kansas'], 'KY' => ['Kentucky', 'Kentucky'], 'LA' => ['Louisiane', 'Louisiana'],
        'ME' => ['Maine', 'Maine'], 'MD' => ['Maryland', 'Maryland'], 'MA' => ['Massachusetts', 'Massachusetts'], 'MI' => ['Michigan', 'Michigan'], 'MN' => ['Minnesota', 'Minnesota'],
        'MS' => ['Mississippi', 'Mississippi'], 'MO' => ['Missouri', 'Missouri'], 'MT' => ['Montana', 'Montana'], 'NE' => ['Nebraska', 'Nebraska'], 'NV' => ['Nevada', 'Nevada'],
        'NH' => ['New Hampshire', 'New Hampshire'], 'NJ' => ['New Jersey', 'New Jersey'], 'NM' => ['Nouveau-Mexique', 'New Mexico'], 'NY' => ['New York', 'New York'],
        'NC' => ['Caroline du Nord', 'North Carolina'], 'ND' => ['Dakota du Nord', 'North Dakota'], 'OH' => ['Ohio', 'Ohio'], 'OK' => ['Oklahoma', 'Oklahoma'], 'OR' => ['Oregon', 'Oregon'],
        'PA' => ['Pennsylvanie', 'Pennsylvania'], 'RI' => ['Rhode Island', 'Rhode Island'], 'SC' => ['Caroline du Sud', 'South Carolina'], 'SD' => ['Dakota du Sud', 'South Dakota'],
        'TN' => ['Tennessee', 'Tennessee'], 'TX' => ['Texas', 'Texas'], 'UT' => ['Utah', 'Utah'], 'VT' => ['Vermont', 'Vermont'], 'VA' => ['Virginie', 'Virginia'],
        'WA' => ['Washington', 'Washington'], 'WV' => ['Virginie-Occidentale', 'West Virginia'], 'WI' => ['Wisconsin', 'Wisconsin'], 'WY' => ['Wyoming', 'Wyoming'],
    ];

    /** Nom du pays dans la langue demandée, ou null (code absent ou inconnu) */
    public static function country(?string $country, string $locale): ?string
    {
        if ($country === null || $country === '' || !Countries::exists($country)) {
            return null;
        }

        return Countries::getName($country, $locale === 'en' ? 'en' : 'fr');
    }

    /** Nom de la province, du territoire ou de l'État dans la langue demandée, ou null (pays sans table, code absent ou inconnu) */
    public static function region(?string $country, ?string $region, string $locale): ?string
    {
        $table = ['CA' => self::CANADA, 'US' => self::UNITED_STATES][$country ?? ''] ?? [];

        return $region === null ? null : ($table[$region][$locale === 'en' ? 1 : 0] ?? null);
    }
}
