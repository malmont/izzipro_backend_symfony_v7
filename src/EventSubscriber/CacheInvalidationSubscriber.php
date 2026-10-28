<?php

namespace App\EventSubscriber;

use App\Cache\CacheInvalidationQueue;
use App\Cache\CacheTags;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionProvider;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Psr\Log\LoggerInterface;

/**
 * Invalidation du cache applicatif après toute écriture Doctrine (26/10/2026, invalidation ciblée depuis le 28/10/2026).
 *
 * Au flush, chaque entité écrite (création, modification, suppression, liaison d'une collection) est traduite en
 * étiquettes par le registre CacheTags :
 * - entité déclarée (ENTITY_TAGS) : la liste de son composant et sa fiche (`<composant>.list`, `<composant>.item.<id>`),
 *   préfixées par la base du site (TenantCacheService::componentTag) ; une traduction ou un enfant vide celles de son
 *   parent (PARENT) ;
 * - entité sans lecture en cache (NO_CACHE_READ) : rien ;
 * - entité non déclarée : **tout le cache du site et de sa base** (étiquettes du code et de la base, posées sur chaque
 *   entrée), comme avant le 28/10, avec une ligne de journal pour repérer ce qui reste à déclarer.
 *
 * Les étiquettes sont déposées dans CacheInvalidationQueue et vidées au COMMIT réel de la connexion par le middleware
 * DBAL (Cache\Doctrine\InvalidateOnCommit*), abandonnées au ROLLBACK ; CacheInvalidationFinalizer vide ce qui resterait
 * en fin de requête, de commande ou de message. Une écriture en SQL direct (DBAL) échappe à Doctrine : appeler
 * TenantCacheService::invalidateTenant(), ou son invalidation par étiquettes du registre.
 */
class CacheInvalidationSubscriber implements EventSubscriber
{
    /** @var array<string, true> classes non déclarées déjà journalisées pendant ce traitement */
    private array $reported = [];

    public function __construct(
        private CacheInvalidationQueue $queue,
        private TenantConnectionProvider $tcp,
        private LoggerInterface $logger,
    ) {
    }

    public function getSubscribedEvents(): array
    {
        return [Events::onFlush];
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $dbname = $em->getConnection()->getParams()['dbname'] ?? null;
        $dbname = is_string($dbname) ? $dbname : '';
        $tenantCode = $this->tcp->getTenantCode() ?: 'master';

        $tags = [];
        $undeclared = false;
        foreach ($this->writtenEntities($em->getUnitOfWork()) as $entity) {
            $entityTags = CacheTags::forEntity($entity);
            if ($entityTags === null) {
                $undeclared = true;
                $this->report($entity, $tenantCode);
                continue;
            }
            foreach ($entityTags as $tag) {
                $tags[] = TenantCacheService::componentTag($dbname, $tenantCode, $tag);
            }
        }
        if ($undeclared) {
            $tags[] = TenantCacheService::tenantTag($tenantCode);
            if ($dbname !== '') {
                $tags[] = TenantCacheService::databaseTag($dbname);
            }
        }
        if ($tags !== []) {
            $this->queue->add($dbname, array_values(array_unique($tags)));
        }
    }

    /** Filet de fin de requête, de commande ou de message (CacheInvalidationFinalizer) : vide ce qui attend encore */
    public function invalidateWrittenTenants(): void
    {
        $this->queue->flushAll();
        $this->reported = [];
    }

    /**
     * Entités dont l'écriture peut changer une réponse en cache : créations, suppressions, modifications d'au moins un
     * champ non ignoré, propriétaires des collections modifiées.
     *
     * @return list<object>
     */
    private function writtenEntities(UnitOfWork $uow): array
    {
        $entities = [...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityDeletions()];
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (array_diff(array_keys($uow->getEntityChangeSet($entity)), CacheTags::ignoredFields($entity)) !== []) {
                $entities[] = $entity;
            }
        }
        foreach ([...$uow->getScheduledCollectionUpdates(), ...$uow->getScheduledCollectionDeletions()] as $collection) {
            $owner = $collection->getOwner();
            if ($owner !== null) {
                $entities[] = $owner;
            }
        }

        return $entities;
    }

    /** Une ligne par classe et par traitement : le journal dit ce qui vide encore tout le site */
    private function report(object $entity, string $tenantCode): void
    {
        $class = get_class($entity);
        if (isset($this->reported[$class])) {
            return;
        }
        $this->reported[$class] = true;
        $this->logger->notice('Cache : entité sans étiquette déclarée, tout le cache du site est vidé', [
            'entity' => $class,
            'tenant' => $tenantCode,
        ]);
    }
}
