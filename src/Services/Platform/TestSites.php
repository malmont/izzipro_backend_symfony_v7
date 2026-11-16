<?php

namespace App\Services\Platform;

use App\Services\Agency\AgencyRepository;

/**
 * Les SITES D'ESSAI, sur lesquels un jeu de données fictives peut être posé (lot ESSAI-DONNÉES, 16/11/2026) : le site de démonstration
 * `demo`, et tout site d'attache ou site géré d'une agence marquée « de test » (`agencies.is_test`, base maître, relue à chaque appel).
 * Aucun autre site, jamais un site client : la marque vient de la fiche d'agence de test, rien n'est écrit en dur hors de `demo`.
 */
class TestSites
{
    public const DEMO = 'demo';

    public function __construct(private readonly AgencyRepository $agencies)
    {
    }

    public function allowed(string $tenantCode): bool
    {
        if ($tenantCode === self::DEMO) {
            return true;
        }
        $home = $this->agencies->findByHome($tenantCode);
        if ($home !== null) {
            return (bool) $home['is_test'];
        }
        $managing = $this->agencies->managing($tenantCode);

        return $managing !== null && (bool) $managing['is_test'];
    }
}
