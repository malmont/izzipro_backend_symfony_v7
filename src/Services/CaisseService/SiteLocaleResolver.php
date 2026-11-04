<?php

namespace App\Services\CaisseService;

use App\Services\OrderService\OrderTaxAddressResolver;
use App\Services\OrderService\TaxEngine;
use Symfony\Component\HttpFoundation\Request;

/**
 * Langue des réponses de la caisse (F18 partie 2b, 02/11/2026) : `?locale=` s'il est donné (fr, en…), sinon la langue du site déduite de
 * l'adresse du magasin, comme la caisse : Canada et Québec → fr, Canada autre province → en, France → fr, États-Unis → en, sinon fr.
 * Accept-Language n'est pas lu (la caisse ne l'envoie pas de façon fiable).
 */
class SiteLocaleResolver
{
    public function __construct(private readonly OrderTaxAddressResolver $storeAddress, private readonly ?\App\Services\TenantEntityManagerProvider $emProvider = null)
    {
    }

    public function fromRequest(Request $request): string
    {
        $asked = (string) $request->query->get('locale', '');
        if (preg_match('/^[a-z]{2}$/', $asked)) {
            return $asked;
        }

        return $this->siteLocale();
    }

    public function siteLocale(): string
    {
        $explicit = $this->explicitLocale();
        if ($explicit !== null) {
            return $explicit;
        }
        $address = $this->siteAddress();

        return match ($address['country'] ?? null) {
            'CA' => ($address['province'] ?? null) === 'QC' || ($address['province'] ?? null) === null ? 'fr' : 'en',
            'US' => 'en',
            default => 'fr',
        };
    }

    /** Pays du site (code ISO à 2 lettres : CA, FR, US, MQ…), null sans adresse ; sert à la locale des montants et des dates des courriels (EmailLocale, 03/11/2026) */
    public function siteCountry(): ?string
    {
        $country = $this->siteAddress()['country'] ?? null;

        return is_string($country) && preg_match('/^[A-Z]{2}$/', $country) ? $country : null;
    }

    /** Langue choisie sur la fiche Entreprise (`entreprise.locale`, console de pilotage lot 2, 04/11/2026), null = déduite ; SQL direct comme l'adresse */
    public function explicitLocale(): ?string
    {
        try {
            $value = $this->emProvider?->getEntityManager()->getConnection()->fetchOne('SELECT locale FROM entreprise ORDER BY id LIMIT 1');
        } catch (\Throwable) {
            return null; // colonne absente (base non migrée) : langue déduite
        }

        return is_string($value) && in_array($value, \App\Entity\Entreprise::LOCALES, true) ? $value : null;
    }

    /** @return array{country: ?string, province: ?string}|null adresse normalisée du site */
    private function siteAddress(): ?array
    {
        // SQL direct : adresse réelle du site, sans le cache d'identité Doctrine (changée dans la même requête par un test ou un import)
        $row = $this->emProvider?->getEntityManager()->getConnection()->fetchAssociative('SELECT a.country, a.state FROM address_entreprise a JOIN entreprise e ON e.id = a.entreprise_id ORDER BY e.id LIMIT 1');

        return TaxEngine::normalizeAddress($row ? ['country' => $row['country'], 'province' => $row['state']] : $this->storeAddress->forStore());
    }
}
