<?php

namespace App\Services\EmailConfigurationService;

use App\Services\CaisseService\SiteLocaleResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * Langue des courriels destinés au client (03/11/2026, lot L6b de l'application e-commerce mobile) : `fr` ou `en`.
 *
 * - `?locale=fr` ou `?locale=en` (ou la locale enregistrée avec la session de paiement) : cette langue ;
 * - absente ou toute autre valeur : la langue du site, même règle que les tickets de caisse (SiteLocaleResolver : adresse de la
 *   fiche Entreprise ; Québec, France et DROM → fr, autres provinces du Canada et États-Unis → en), le français restant le dernier recours.
 *
 * `intl()` donne la locale ICU des montants et des dates (langue + pays du site : fr_CA, fr_FR, en_US…), pour `format_currency` et
 * `format_datetime` dans les gabarits. Aucun écouteur ne fixe `$request->getLocale()` (toujours `en`, défaut du framework) : chaque
 * point d'envoi passe par ici. Les courriels destinés au marchand restent en français.
 */
final class EmailLocale
{
    public const FRENCH = 'fr';
    public const ENGLISH = 'en';

    public function __construct(private readonly SiteLocaleResolver $site)
    {
    }

    /** `fr` ou `en` d'après la valeur demandée (`?locale=`, `fr-CA`, `EN`…), sinon la langue du site */
    public function language(mixed $asked): string
    {
        $code = is_string($asked) ? strtolower(substr(trim($asked), 0, 2)) : '';
        if ($code === self::FRENCH || $code === self::ENGLISH) {
            return $code;
        }

        return $this->site->siteLocale() === self::ENGLISH ? self::ENGLISH : self::FRENCH;
    }

    public function fromRequest(Request $request): string
    {
        return $this->language($request->query->get('locale'));
    }

    /** Locale ICU des montants et des dates : langue + pays du site (`fr_CA`, `en_US`, `fr_FR`…), la langue seule sans pays connu */
    public function intl(string $language): string
    {
        $country = $this->site->siteCountry();

        return $language . ($country !== null ? '_' . $country : '');
    }

    /**
     * Variables de gabarit d'un courriel client : `locale` (fr|en, pour `|trans`) et `intlLocale` (pour `format_currency`, `format_datetime`).
     *
     * @return array{locale: string, intlLocale: string}
     */
    public function context(string $language): array
    {
        return ['locale' => $language, 'intlLocale' => $this->intl($language)];
    }
}
