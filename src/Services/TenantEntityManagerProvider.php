<?php

namespace App\Services;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Configuration;
use App\EventSubscriber\CacheInvalidationSubscriber; 

class TenantEntityManagerProvider
{
    /**
     * @var EntityManagerInterface|null Le cache de notre instance d'EntityManager.
     */
    private ?EntityManagerInterface $em = null;

    /**
     * @var string|null Le nom de la base de données pour laquelle l'EM en cache a été créé.
     */
    private ?string $currentDbName = null;

    public function __construct(
        private TenantConnectionProvider $connectionProvider,
        private Configuration $ormConfig,
        private CacheInvalidationSubscriber $cacheSubscriber 
    ) {}

    /**
     * Retourne une instance partagée de l'EntityManager pour le tenant courant.
     * Ne crée une nouvelle instance que si c'est la première fois ou si le tenant a changé.
     */
    public function getEntityManager(): EntityManagerInterface
    {
        $connection = $this->connectionProvider->getConnection();
        
        if (!$connection->isConnected()) {
            $connection->connect();
        }
        $targetDbName = $connection->getDatabase();

        if ($this->em instanceof EntityManagerInterface && $this->em->isOpen() && $this->currentDbName === $targetDbName) {
            return $this->em;
        }

        $config = $this->ormConfig;

        // On crée le nouvel EM.
        $this->em = new EntityManager($connection, $config);

        $this->em->getEventManager()->addEventSubscriber($this->cacheSubscriber);
        
        // On mémorise pour quelle BDD cet EM a été créé.
        $this->currentDbName = $targetDbName;

        return $this->em;
    }

    public function switchTenant(string $tenantDbName, ?string $tenantCode = null): void
    {
        $this->connectionProvider->switchTenant($tenantDbName, $tenantCode);
    }
}