<?php

namespace App\EventSubscriber;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\ProductService\ProductUsageGuard;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Garde de suppression d'un article ou d'une variante (ART-SUPPR, 13/11/2026), au niveau de Doctrine : elle couvre d'un coup la route de
 * l'application (DELETE /api/products/{id}), celle d'API Platform, la suppression d'une variante et EasyAdmin. Voir ProductUsageGuard.
 */
final class ProductUsageGuardSubscriber implements EventSubscriber
{
    private bool $suspended = false;

    public function getSubscribedEvents(): array
    {
        return [Events::onFlush];
    }

    /** Suspension explicite (jeux de données de test, nettoyages assumés) */
    public function suspend(bool $on = true): void
    {
        $this->suspended = $on;
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->suspended) {
            return;
        }
        $em = $args->getObjectManager();
        $conn = $em->getConnection();
        $deletedProducts = [];
        foreach ($em->getUnitOfWork()->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Product && $entity->getId() !== null) {
                ProductUsageGuard::assertProductDeletable($conn, $entity);
                $deletedProducts[spl_object_id($entity)] = true;
                $this->releaseLinks($em, $entity);
            }
        }
        foreach ($em->getUnitOfWork()->getScheduledEntityDeletions() as $entity) {
            // variante supprimée seule (avec son article : déjà vérifiée par l'article)
            if ($entity instanceof ProductVariant && $entity->getId() !== null && !($entity->getProduct() !== null && isset($deletedProducts[spl_object_id($entity->getProduct())]))) {
                ProductUsageGuard::assertVariantDeletable($conn, $entity);
            }
        }
    }

    /**
     * Liens sans histoire qui n'ont pas de règle de suppression en base (avant : erreur de clé étrangère) : les articles associés
     * (related_product, purs liens) partent avec l'article ; un contenu « véhicule » de la page (vehicle, lien facultatif) est gardé et
     * détaché. Le réglage de location, les images, les traductions et la fiche véhicule de l'article sont déjà supprimés par Doctrine.
     */
    private function releaseLinks(\Doctrine\ORM\EntityManagerInterface $em, Product $product): void
    {
        $uow = $em->getUnitOfWork();
        foreach ($em->getRepository(\App\Entity\RelatedProduct::class)->findBy(['product' => $product]) as $link) {
            $uow->scheduleForDelete($link);
        }
        foreach ($em->getRepository(\App\Entity\Vehicle::class)->findBy(['product' => $product]) as $vehicle) {
            $vehicle->setProduct(null);
            $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(\App\Entity\Vehicle::class), $vehicle);
        }
    }
}
