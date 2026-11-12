<?php

namespace App\Support\Services;

/**
 * Délai de réponse en heures ouvrées (lot 1) : seules les heures des jours ouvrés comptent (journées entières, fuseau du site, sans jours
 * fériés) ; 48 h ouvrées du lundi au vendredi = 2 jours ouvrés. Les heures d'ouverture pourront s'ajouter sans changer cette interface.
 */
final class BusinessClock
{
    public const MAX_HOURS = 336;

    /** @param list<int> $days 1 lundi … 7 dimanche */
    public static function due(\DateTimeImmutable $from, int $hours, array $days, \DateTimeZone $tz): \DateTimeImmutable
    {
        $t = $from->setTimezone($tz);
        $left = max(0, min(self::MAX_HOURS, $hours)) * 3600;
        $days = $days === [] ? [1, 2, 3, 4, 5] : $days;
        for ($guard = 0; $left > 0 && $guard < 400; $guard++) {
            $dayEnd = $t->setTime(0, 0)->modify('+1 day');
            if (!in_array((int) $t->format('N'), $days, true)) {
                $t = $dayEnd; // jour non ouvré : on passe au lendemain minuit
                continue;
            }
            $available = $dayEnd->getTimestamp() - $t->getTimestamp();
            if ($left <= $available) {
                return $t->modify("+$left seconds")->setTimezone(new \DateTimeZone('UTC'));
            }
            $left -= $available;
            $t = $dayEnd;
        }

        return $t->setTimezone(new \DateTimeZone('UTC'));
    }
}
