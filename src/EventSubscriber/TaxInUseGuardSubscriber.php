<?php

namespace App\EventSubscriber;

use App\Entity\Tax;
use App\Services\OrderService\TaxInUseException;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Taxe utilisée (PAYS-1, 12/11/2026) : dès qu'une ligne order_tax la référence, son taux, son libellé, son code, sa catégorie,
 * son pays, sa région et son début de validité ne changent plus, et elle ne se supprime plus (TAX_IN_USE). Restent permis :
 * l'archivage, la fin de validité (borner l'ancienne taxe quand une nouvelle, datée, la remplace) et le type (texte libre).
 * Les commandes gardent de toute façon leurs valeurs gelées (OrderTax::freeze) : ce garde protège la cohérence de la table.
 */
final class TaxInUseGuardSubscriber implements EventSubscriber
{
    public const PROTECTED_FIELDS = ['rate', 'name', 'code', 'category', 'country', 'province', 'validFrom'];

    public function getSubscribedEvents(): array
    {
        return [Events::onFlush];
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Tax || $entity->getId() === null) {
                continue;
            }
            $changed = array_values(array_intersect(array_keys($uow->getEntityChangeSet($entity)), self::PROTECTED_FIELDS));
            if ($changed !== [] && self::isUsed($em->getConnection(), (int) $entity->getId())) {
                throw new TaxInUseException((int) $entity->getId(), $changed);
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Tax && $entity->getId() !== null && self::isUsed($em->getConnection(), (int) $entity->getId())) {
                throw new TaxInUseException((int) $entity->getId(), [], true);
            }
        }
    }

    public static function isUsed(\Doctrine\DBAL\Connection $conn, int $taxId): bool
    {
        return (bool) $conn->fetchOne('SELECT 1 FROM order_tax WHERE tax_id = ? LIMIT 1', [$taxId]);
    }
}
