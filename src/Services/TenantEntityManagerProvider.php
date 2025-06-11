<?php
// src/Services/TenantEntityManagerProvider.php

namespace App\Services;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Configuration as ORMConfiguration;
use Doctrine\Common\EventManager;
use App\Services\TenantConnectionProvider;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;


class TenantEntityManagerProvider
{
    public function __construct(
        private TenantConnectionProvider $connectionProvider,
        private ORMConfiguration $ormConfig,
        private EventManager $eventManager
    ) {}

    /**
     * Retourne un nouvel EntityManager pour la connexion courante (déjà swappée)
     */

    public function getEntityManager(): EntityManagerInterface
    {
        $connection = $this->connectionProvider->getConnection();
        $tenantCode = $this->connectionProvider->getTenantCode() ?? 'master';
        $config = clone $this->ormConfig;
        

        // Utilise un cache en mémoire, non persistant (aucun partage entre requêtes ni tenants)
        $metadataCache = new ArrayAdapter();
        $queryCache    = new ArrayAdapter();
        $resultCache   = new ArrayAdapter();

        $config->setMetadataCache($metadataCache);
        $config->setQueryCache($queryCache);
        $config->setResultCache($resultCache);

        $em = EntityManager::create($connection, $config, $connection->getEventManager());
        if (method_exists($em->getMetadataFactory(), 'clearLoadedMetadata')) {
            $em->getMetadataFactory()->clearLoadedMetadata();
        }
        return $em;
    }



    // Optionnel : si tu veux forcer un switch manuellement (rare, car normalement fait par le listener)
    public function switchTenant(string $tenantDbName, ?string $tenantCode = null): void
    {
        $this->connectionProvider->switchTenant($tenantDbName, $tenantCode);
        // Ici, pas besoin de stocker d'EM : getEntityManager() le fera sur la bonne connexion ensuite
    }
    
}

