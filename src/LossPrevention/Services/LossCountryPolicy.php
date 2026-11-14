<?php

namespace App\LossPrevention\Services;

/**
 * Ce que le pays et la région du site permettent (décisions du propriétaire du 14/11/2026, `docs/prevention-pertes.md` § 7) : calcul pur.
 * À FAIRE RELIRE PAR UN AVOCAT avant l'activation chez un vrai client (décision 15).
 *
 * - Notice aux salariés : l'analyse ne démarre pas, en France (départements et régions d'outre-mer compris) et au Québec, tant que le gérant
 *   n'a pas confirmé avoir informé ses salariés ; ailleurs, simple rappel. Pays ou province illisible : exigée, dans le doute.
 * - Règles par personne (actes d'un même compte dans le temps) : permises aux États-Unis seulement, allumées d'office en Floride ; éteintes
 *   en France et au Québec jusqu'à l'avis d'un avocat ; tout autre pays : non permises tant que rien n'est décidé (choix prudent).
 * - Jamais, nulle part : visages, son, caméra en cabine, analyse de gestes par vidéo.
 */
final class LossCountryPolicy
{
    public const NEVER = ['faces', 'audio', 'fitting_room_camera', 'gesture_analysis'];
    private const FRANCE = ['FR', 'MQ', 'GP', 'GF', 'RE', 'YT'];
    /** Pays où les règles par personne sont permises (décision du propriétaire du 14/11/2026 : les États-Unis seulement) */
    public const PERSON_PATTERNS_COUNTRIES = ['US'];
    /** Provinces et territoires du Canada autres que le Québec : simple rappel */
    private const CANADA_OTHER = ['ON', 'BC', 'AB', 'SK', 'MB', 'NB', 'NS', 'NL', 'PE', 'YT', 'NT', 'NU'];

    /**
     * Dans le doute, la notice est exigée : pays illisible (champ libre de la fiche du site : vide, « FRA », un nom de département…), ou
     * Canada sans province reconnue. Les règles par personne, elles, sont fermées dans le doute.
     */
    public static function staffNoticeRequired(?string $country, ?string $region): bool
    {
        if ($country === null || $country === '') {
            return true;
        }

        return in_array($country, self::FRANCE, true) || ($country === 'CA' && ($region === 'QC' || !in_array($region, self::CANADA_OTHER, true)));
    }

    /**
     * GARDE BLOQUANTE (chef de projet, 14/11/2026) : les règles par personne ne sont JAMAIS permises là où la notice aux salariés est exigée
     * (France, Québec, pays ou province illisible). Elles relisent une fenêtre de clôtures, de retours ou de ventes SANS tenir compte du
     * plancher de la notice (`analysis_floor_sequence`) : elles compteraient des actes d'avant l'information des salariés. Tant que
     * `CashShortageSeriesRule`, `RefundSeriesRule` et `DiscountSeriesRule` ne bornent pas leur fenêtre à ce plancher, cette garde reste, quelle
     * que soit la liste des pays permis. Test : `LossCountryPolicyTest`.
     */
    public static function personPatternsAllowed(?string $country, ?string $region): bool
    {
        if (self::staffNoticeRequired($country, $region)) {
            return false;
        }

        return in_array($country, self::PERSON_PATTERNS_COUNTRIES, true);
    }

    /** Valeur quand le site n'a rien choisi */
    public static function personPatternsDefault(?string $country, ?string $region): bool
    {
        return $country === 'US' && $region === 'FL';
    }
}
