<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\HomeTilesService;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Textes du module, écrits par le serveur en français et en anglais (`translations/pertes.{fr,en}.yaml`) : libellés des règles, résumés des
 * constats, lignes des preuves, notice aux salariés. Un résumé ne nomme jamais personne (`docs/prevention-pertes.md`, principe P3).
 */
class LossTexts
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /** @param array<string, string|int> $params sans les « % » */
    public function t(string $key, string $locale, array $params = []): string
    {
        $with = [];
        foreach ($params as $name => $value) {
            $with['%' . $name . '%'] = (string) $value;
        }

        return $this->translator->trans($key, $with, 'pertes', $locale);
    }

    public function has(string $key, string $locale): bool
    {
        return $this->translator->trans($key, [], 'pertes', $locale) !== $key;
    }

    public static function money(int $cents, string $locale, string $currency): string
    {
        return HomeTilesService::money($cents, $locale, $currency);
    }

    /** Date et heure du magasin, prêtes à afficher : « 14/11/2026 15 h 42 » / « 2026-11-14 3:42 PM » */
    public static function dateTime(\DateTimeImmutable $utc, \DateTimeZone $tz, string $locale): string
    {
        $local = $utc->setTimezone($tz);

        return $locale === 'en' ? $local->format('Y-m-d g:i A') : $local->format('d/m/Y H \h i');
    }

    public static function day(\DateTimeImmutable $day, string $locale): string
    {
        return $locale === 'en' ? $day->format('Y-m-d') : $day->format('d/m/Y');
    }
}
