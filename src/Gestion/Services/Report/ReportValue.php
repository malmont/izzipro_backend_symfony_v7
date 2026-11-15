<?php

namespace App\Gestion\Services\Report;

use App\Gestion\Services\HomeTilesService;

/**
 * Lot DOC-1 : écrit une valeur d'un bloc de la console pour le papier, selon son unité (celles de `docs/console-gestion.md` § 4) :
 * `money` en cents de la devise du site, `percent` déjà en pour cent, `count`, `days`, `ratio` (coefficient), `date`, `month`, `text`.
 * Une valeur absente s'écrit par un tiret. Aucun calcul : le nombre écrit est celui du bloc.
 */
final class ReportValue
{
    public const NONE = '—';

    public static function write(mixed $value, ?string $unit, string $locale, string $currency): string
    {
        if ($value === null || $value === '') {
            return self::NONE;
        }
        $en = $locale === 'en';
        $number = static function (float $n, int $decimals) use ($en): string {
            $formatter = new \NumberFormatter($en ? 'en_CA' : 'fr_CA', \NumberFormatter::DECIMAL);
            $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 0);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            $written = $formatter->format($n);

            return $written === false ? (string) $n : $written;
        };
        // Un texte reste un texte, même s'il ressemble à un nombre (article « 2024 », référence « 007 ») : seule une unité de nombre est mise en forme
        if (in_array($unit, ['text', 'month', 'date', null], true) || !is_numeric($value)) {
            if ($unit === 'date' && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
                $written = (new \IntlDateFormatter($en ? 'en_CA' : 'fr_CA', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, $en ? 'MMMM d, y' : 'd MMMM y'))->format(new \DateTimeImmutable(substr($value, 0, 10), new \DateTimeZone('UTC')));

                return $written === false ? substr($value, 0, 10) : $written;
            }

            return is_scalar($value) ? (string) $value : self::NONE;
        }

        return match ($unit) {
            'money' => HomeTilesService::money((int) round((float) $value), $locale, $currency),
            'percent' => $number((float) $value, 2) . ($en ? '%' : ' %'),
            'days' => $number((float) $value, 0) . ' ' . ($en ? ((int) round((float) $value) === 1 ? 'day' : 'days') : ((int) round((float) $value) > 1 ? 'jours' : 'jour')),
            'ratio' => $number((float) $value, 2),
            'count' => $number((float) $value, 2), // un compte entier s'écrit sans décimale ; une moyenne garde les siennes
            default => $number((float) $value, 2),
        };
    }
}
