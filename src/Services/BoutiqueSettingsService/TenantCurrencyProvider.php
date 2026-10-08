<?php

namespace App\Services\BoutiqueSettingsService;

use App\Entity\Entreprise;
use App\Services\TenantEntityManagerProvider;

/**
 * Devise du site courant (fiche entreprise, colonne « currency », code ISO 4217) : une seule devise par site, lue une
 * fois par requête ; CAD à défaut. Tous les montants renvoyés par la boutique sont dans cette devise, sans conversion.
 */
final class TenantCurrencyProvider
{
    public const DEFAULT = 'CAD';

    /** base => code, pour une requête qui changerait de tenant */
    private array $codes = [];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    public function code(): string
    {
        $em = $this->emProvider->getEntityManager();
        $db = (string) ($em->getConnection()->getParams()['dbname'] ?? '');
        if (!isset($this->codes[$db])) {
            $currency = $em->getRepository(Entreprise::class)->findOneBy([])?->getCurrency();
            $this->codes[$db] = is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) ? $currency : self::DEFAULT;
        }

        return $this->codes[$db];
    }
}
