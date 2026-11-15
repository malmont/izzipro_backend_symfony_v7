<?php

namespace App\Services\Agency;

use App\Services\TenantConnectionManager;

/**
 * L'agence d'une requête (lot AG-1). Règle de sécurité : elle se lit TOUJOURS du site sur lequel le compte est connecté (le site de la
 * requête, auquel le jeton est lié), jamais d'un paramètre, d'un en-tête ou du corps. Une route `/api/agency/*` appelée ailleurs que
 * sur le site d'attache d'une agence, ou sans site reconnu, répond 403 `AGENCY_SCOPE`. Une agence fermée n'a plus d'espace.
 */
class AgencyContext
{
    public function __construct(private readonly TenantConnectionManager $tenants, private readonly AgencyRepository $repository)
    {
    }

    /** @return array<string, mixed> l'agence dont le site courant est le site d'attache */
    public function current(): array
    {
        $code = (string) $this->tenants->getCurrentTenantCode();
        $agency = $code !== '' ? $this->repository->findByHome($code) : null;
        if ($agency === null || $agency['status'] === 'closed') {
            throw new AgencyException('AGENCY_SCOPE', 403);
        }

        return $agency;
    }
}
