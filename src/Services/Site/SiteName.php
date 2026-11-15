<?php

namespace App\Services\Site;

use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;

/**
 * Le nom du site courant tel qu'on le montre à une personne (15/11/2026) : celui de la FICHE du site (`entreprise.name`, que le gérant
 * règle), sinon celui que la plateforme lui connaît (table des sites), sinon son code. Une seule source pour les écrans et les courriels
 * de l'espace d'administration (`GET /api/support/me`, courriel d'invitation d'un agent, vérification d'un lien) : avant, la fiche et la
 * table des sites se partageaient ces trois réponses et pouvaient donner deux noms au même site.
 */
class SiteName
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantPublicHostResolver $hosts,
    ) {
    }

    public function name(): string
    {
        try {
            $name = trim((string) ($this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT name FROM entreprise ORDER BY id LIMIT 1') ?: ''));
        } catch (\Throwable) {
            $name = '';
        }
        if ($name !== '') {
            return $name;
        }
        $code = (string) $this->tenants->getCurrentTenantCode();
        $known = $code !== '' ? trim((string) ($this->hosts->describe($code)['name'] ?? '')) : '';

        return $known !== '' ? $known : $code;
    }
}
