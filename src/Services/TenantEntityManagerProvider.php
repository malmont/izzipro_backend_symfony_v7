<?php

namespace App\Services;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Configuration;
use Doctrine\DBAL\Connection;

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
     * OPTIMISÉ : Ne force pas la connexion SQL si ce n'est pas nécessaire.
     */
    public function getEntityManager(): EntityManagerInterface
    {
        $connection = $this->connectionProvider->getConnection();

        // --- OPTIMISATION LAZY LOADING ---
        // Au lieu de faire $connection->connect() (coûteux) pour demander le nom de la DB,
        // on lit simplement la configuration stockée en mémoire PHP (instantané).
        $params = $connection->getParams();
        $targetDbName = $params['dbname'] ?? '';

        // Si un EM existe déjà, qu'il est ouvert et qu'il correspond à la bonne BDD,
        // alors on le réutilise.
        if ($this->em instanceof EntityManagerInterface 
            && $this->em->isOpen() 
            && $this->currentDbName === $targetDbName
        ) {
            return $this->em;
        }

        // Sinon, on crée un nouveau (le switch s'est produit ou c'est le premier appel).
        // On utilise `new EntityManager` car c'est plus direct quand on a déjà la connexion et la config.
        $this->em = $this->createEntityManager($connection, $this->ormConfig);

        // On mémorise pour quelle BDD cet EM a été créé.
        $this->currentDbName = $targetDbName;

        return $this->em;
    }

    public function switchTenant(string $tenantDbName, ?string $tenantCode = null): void
    {
        $this->connectionProvider->switchTenant($tenantDbName, $tenantCode);
        // Ici, pas besoin de stocker d'EM : getEntityManager() le fera sur la bonne connexion au prochain appel.
    }

    /**
     * Factory method pour créer l'EntityManager (Permet le Mocking en tests unitaires)
     */
    protected function createEntityManager(Connection $connection, Configuration $config): EntityManagerInterface
    {
        return new EntityManager($connection, $config);
    }
}