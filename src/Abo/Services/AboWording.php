<?php

namespace App\Abo\Services;

/**
 * Le rythme d'une formule en toutes lettres (« ce que verra l'abonné », aperçus de l'assistant), une seule règle (20/11/2026, défaut vu par la
 * gestion web : « tous les 2 semaines ») : le déterminant s'accorde avec l'unité, féminine pour la semaine, masculine pour le mois et l'an.
 * - une période : « par semaine », « par mois », « par an » ; « per week », « per month », « per year » ;
 * - plusieurs : « toutes les 2 semaines », « tous les 3 mois », « tous les 2 ans » ; « every 2 weeks », « every 3 months », « every 2 years » ;
 * - une durée (formule à une seule période) : « 1 semaine », « 2 semaines », « 6 mois », « 1 an », « 2 ans » ; « 1 week », « 2 weeks ».
 */
final class AboWording
{
    /** Le rythme : « par mois », « toutes les 2 semaines », « every 3 months » */
    public static function every(string $interval, int $count, bool $en): string
    {
        if ($count <= 1) {
            return ($en ? 'per ' : 'par ') . self::unit($interval, 1, $en);
        }
        if ($en) {
            return sprintf('every %d %s', $count, self::unit($interval, $count, true));
        }

        return sprintf('%s les %d %s', $interval === 'week' ? 'toutes' : 'tous', $count, self::unit($interval, $count, false));
    }

    /** Une durée : « 1 semaine », « 2 semaines », « 6 mois », « 2 ans », « 3 weeks » */
    public static function duration(string $interval, int $count, bool $en): string
    {
        $count = max(1, $count);

        return sprintf('%d %s', $count, self::unit($interval, $count, $en));
    }

    /** L'unité accordée au nombre (« mois » est invariable) */
    public static function unit(string $interval, int $count, bool $en): string
    {
        $plural = $count > 1;

        return match ($interval) {
            'week' => $en ? ($plural ? 'weeks' : 'week') : ($plural ? 'semaines' : 'semaine'),
            'year' => $en ? ($plural ? 'years' : 'year') : ($plural ? 'ans' : 'an'),
            default => $en ? ($plural ? 'months' : 'month') : 'mois',
        };
    }
}
