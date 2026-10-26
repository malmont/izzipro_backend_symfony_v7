<?php

namespace App\EventSubscriber;

use App\Entity\AiJob;
use App\Entity\AiUsage;
use App\Entity\ContentAuditLog;
use App\Entity\OtpCode;
use App\Entity\PosAuditLog;
use App\Entity\PosIdempotencyKey;
use App\Entity\RefreshToken;
use App\Entity\SharedMedia;
use App\Entity\User;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionProvider;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Invalidation du cache applicatif après toute écriture Doctrine (26/10/2026).
 *
 * Toute écriture d'une entité (création, modification, suppression, liaison d'une collection) vide tout le cache du
 * site courant et de sa base : chaque entrée de TenantCacheService porte ces deux étiquettes, quelles que soient ses
 * autres étiquettes. L'étiquette de la base couvre les codes qui partagent une base (esgboost et esgboost1) et les
 * commandes qui changent de base sans code de site. Aucune table de correspondance entité → clés à tenir à jour : une nouvelle entité, une traduction ou un
 * nouveau composant de la landing page sont couverts sans rien ajouter ici.
 *
 * Avant : une table de correspondance par entité, dont les étiquettes ne correspondaient pas à celles posées par les
 * contrôleurs (« demopresentations_all » invalidé, « presentations_all » et « demo_presentations_all » posés), et qui
 * ignorait les traductions (presentation_translation…) : une modification restait invisible jusqu'à une heure.
 *
 * Seules les écritures techniques, fréquentes et sans effet sur une réponse en cache, sont ignorées (IGNORED_CLASSES,
 * IGNORED_FIELDS). Une écriture en SQL direct (DBAL) échappe à Doctrine : appeler TenantCacheService::invalidateTenant().
 *
 * Deux passages : juste après le flush, puis à la fin de la requête, de la commande ou du message
 * (CacheInvalidationFinalizer). Le second couvre le flush fait dans une transaction encore ouverte : une lecture
 * concurrente pouvait remettre en cache l'ancienne valeur avant la validation.
 */
class CacheInvalidationSubscriber implements EventSubscriber
{
    /** Entités techniques : jetons, codes, journaux, suivi de l'IA. Aucune réponse en cache ne les lit. */
    private const IGNORED_CLASSES = [
        RefreshToken::class,
        OtpCode::class,
        AiUsage::class,
        AiJob::class,
        ContentAuditLog::class,
        PosAuditLog::class,
        PosIdempotencyKey::class,
    ];

    /** Champs dont la seule modification ne change aucune réponse en cache (compteurs écrits pendant une lecture). */
    private const IGNORED_FIELDS = [
        SharedMedia::class => ['downloadCount'],
        User::class => ['lastLoginAt'],
    ];

    /** @var array<string, string> étiquettes (code du site, base) écrites dans le flush en cours */
    private array $pending = [];

    /** @var array<string, string> étiquettes écrites depuis le début de la requête, de la commande ou du message */
    private array $written = [];

    public function __construct(private TagAwareCacheInterface $cache, private TenantConnectionProvider $tcp)
    {
    }

    public function getSubscribedEvents(): array
    {
        return [Events::onFlush, Events::postFlush];
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        if (!$this->touchesCachedContent($em->getUnitOfWork())) {
            return;
        }
        $tag = TenantCacheService::tenantTag($this->tcp->getTenantCode() ?: 'master');
        $this->pending[$tag] = $tag;
        $dbname = $em->getConnection()->getParams()['dbname'] ?? null;
        if (is_string($dbname) && $dbname !== '') {
            $tag = TenantCacheService::databaseTag($dbname);
            $this->pending[$tag] = $tag;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pending !== []) {
            $this->invalidate($this->pending);
            $this->written += $this->pending;
        }
        $this->pending = [];
    }

    /** Second passage, en fin de requête, de commande ou de message (voir l'en-tête de la classe). */
    public function invalidateWrittenTenants(): void
    {
        if ($this->written !== []) {
            $this->invalidate($this->written);
        }
        $this->written = [];
    }

    private function touchesCachedContent(UnitOfWork $uow): bool
    {
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityDeletions()] as $entity) {
            if (!$this->isIgnoredClass($entity)) {
                return true;
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->isIgnoredClass($entity)) {
                continue;
            }
            $ignoredFields = $this->ignoredFields($entity);
            if (array_diff(array_keys($uow->getEntityChangeSet($entity)), $ignoredFields) !== []) {
                return true;
            }
        }
        foreach ([...$uow->getScheduledCollectionUpdates(), ...$uow->getScheduledCollectionDeletions()] as $collection) {
            $owner = $collection->getOwner();
            if ($owner === null || !$this->isIgnoredClass($owner)) {
                return true;
            }
        }

        return false;
    }

    private function isIgnoredClass(object $entity): bool
    {
        foreach (self::IGNORED_CLASSES as $class) {
            if ($entity instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function ignoredFields(object $entity): array
    {
        foreach (self::IGNORED_FIELDS as $class => $fields) {
            if ($entity instanceof $class) {
                return $fields;
            }
        }

        return [];
    }

    /** @param array<string, string> $tags étiquettes de site et de base (TenantCacheService::tenantTag, databaseTag) */
    private function invalidate(array $tags): void
    {
        try {
            $this->cache->invalidateTags(array_values($tags));
        } catch (\Throwable) {
            // Redis indisponible : l'écriture est faite, le cache expirera de lui-même ; ne pas faire échouer la requête
        }
    }
}
