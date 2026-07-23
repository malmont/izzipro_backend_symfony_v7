<?php

namespace App\Services;

use Doctrine\ORM\EntityManagerInterface;

class TenantEntityManagerProvider
{
    public function __construct(
        private TenantConnectionProvider $connectionProvider,
        private EntityManagerInterface $defaultEm,
    ) {}

    /**
     * Retourne l'EntityManager de l'application connecté au tenant courant.
     */
    public function getEntityManager(): EntityManagerInterface
    {
        return $this->defaultEm;
    }

    public function switchTenant(string $tenantDbName, ?string $tenantCode = null): void
    {
        $this->connectionProvider->switchTenant($tenantDbName, $tenantCode);
        $this->defaultEm->clear();
    }
}