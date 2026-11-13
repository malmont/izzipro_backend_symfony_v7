<?php

namespace App\Gestion\Analysis;

/**
 * Libellé RÉDIGÉ d'une plage de jours, pour la console IA de la gestion (lot W2-b, 13/11/2026) : « du 1er au 13 novembre 2026 »,
 * jamais « 1-13 nov. ». L'année est toujours écrite : le texte d'une réponse se relit des mois plus tard. Le serveur l'écrit, le modèle
 * le recopie.
 *
 * Français : « le 13 novembre 2026 », « du 1er au 13 novembre 2026 », « du 28 octobre au 13 novembre 2026 », « du 15 décembre 2025 au
 * 13 janvier 2026 ». Anglais : « November 13, 2026 », « November 1 to 13, 2026 », « October 28 to November 13, 2026 », « December 15,
 * 2025 to January 13, 2026 ».
 */
final class RangeLabel
{
    private const MONTHS_FR = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    private const MONTHS_EN = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /** Un jour rédigé, sans article : « 11 novembre 2026 », « November 11, 2026 » (lot W2-c : les dates données au modèle et rendues dans un texte) */
    public static function day(string $date, string $locale): string
    {
        $d = new \DateTimeImmutable($date);

        return $locale === 'en' ? self::MONTHS_EN[(int) $d->format('n')] . ' ' . $d->format('j') . ', ' . $d->format('Y')
            : ($d->format('j') === '1' ? '1er' : $d->format('j')) . ' ' . self::MONTHS_FR[(int) $d->format('n')] . ' ' . $d->format('Y');
    }

    /** @param string $from premier jour, AAAA-MM-JJ @param string $to dernier jour compris, AAAA-MM-JJ */
    public static function of(string $from, string $to, string $locale): string
    {
        $f = new \DateTimeImmutable($from);
        $t = new \DateTimeImmutable($to);
        $sameYear = $f->format('Y') === $t->format('Y');
        $sameMonth = $sameYear && $f->format('n') === $t->format('n');
        if ($locale === 'en') {
            $month = fn (\DateTimeImmutable $d): string => self::MONTHS_EN[(int) $d->format('n')];
            $full = fn (\DateTimeImmutable $d): string => $month($d) . ' ' . $d->format('j') . ', ' . $d->format('Y');

            return match (true) {
                $from === $to => $full($f),
                $sameMonth => $month($f) . ' ' . $f->format('j') . ' to ' . $t->format('j') . ', ' . $t->format('Y'),
                $sameYear => $month($f) . ' ' . $f->format('j') . ' to ' . $full($t),
                default => $full($f) . ' to ' . $full($t),
            };
        }
        $day = fn (\DateTimeImmutable $d): string => $d->format('j') === '1' ? '1er' : $d->format('j');
        $month = fn (\DateTimeImmutable $d): string => self::MONTHS_FR[(int) $d->format('n')];
        $full = fn (\DateTimeImmutable $d): string => $day($d) . ' ' . $month($d) . ' ' . $d->format('Y');

        return match (true) {
            $from === $to => 'le ' . $full($f),
            $sameMonth => 'du ' . $day($f) . ' au ' . $full($t),
            $sameYear => 'du ' . $day($f) . ' ' . $month($f) . ' au ' . $full($t),
            default => 'du ' . $full($f) . ' au ' . $full($t),
        };
    }
}
