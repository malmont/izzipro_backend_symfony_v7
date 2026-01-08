<?php

namespace App\Services;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Configuration;


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
        private Configuration $ormConfig, // On injecte la configuration ORM globale de Doctrine

    ) {}

    /**
     * Retourne une instance partagée de l'EntityManager pour le tenant courant.
     * Ne crée une nouvelle instance que si c'est la première fois ou si le tenant a changé.
     */
    public function getEntityManager(): EntityManagerInterface
    {
        $connection = $this->connectionProvider->getConnection();

        // On s'assure que la connexion est active pour pouvoir lire le nom de la BDD.
        if (!$connection->isConnected()) {
            $connection->connect();
        }
        $targetDbName = $connection->getDatabase();

        // Si un EM existe déjà, qu'il est ouvert et qu'il a été créé pour la BDD actuelle,
        // alors on le réutilise.
        if ($this->em instanceof EntityManagerInterface && $this->em->isOpen() && $this->currentDbName === $targetDbName) {
            return $this->em;
        }

        $config = $this->ormConfig;

        // On crée le nouvel EM. On utilise `new EntityManager` car c'est plus direct
        // quand on a déjà la connexion et la config.
        // On crée le nouvel EM via une méthode protégée (pour pouvoir la mocker en test)
        $this->em = $this->createEntityManager($connection, $config);

        // On mémorise pour quelle BDD cet EM a été créé.
        $this->currentDbName = $targetDbName;

        return $this->em;
    }
    public function switchTenant(string $tenantDbName, ?string $tenantCode = null): void
    {
        $this->connectionProvider->switchTenant($tenantDbName, $tenantCode);
        // Ici, pas besoin de stocker d'EM : getEntityManager() le fera sur la bonne connexion ensuite
    }

    /**
     * Factory method pour créer l'EntityManager (Permet le Mocking en test unitaires)
     */
    protected function createEntityManager(\Doctrine\DBAL\Connection $connection, Configuration $config): EntityManagerInterface
    {
        return new EntityManager($connection, $config);
    }
}
