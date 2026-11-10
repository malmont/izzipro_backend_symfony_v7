<?php

namespace App\EventListener;

use App\Entity\Commande;
use App\Entity\NoteDeFrais;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Rattachement automatique d'une note de frais ou d'une commande fournisseur au voyage d'achat qui couvre sa date (lot B1a, 11/11/2026,
 * `docs/budgets-gestion.md` § 3), quel que soit le chemin d'écriture (routes, confirmation d'un document lu, EasyAdmin) : à la création, et
 * quand la date change. Un choix du gérant (`trip_source` manual, rattaché ou détaché) n'est jamais touché ; un rattachement posé par le code
 * dans la même écriture non plus ; les commandes de reprise d'historique restent hors voyage.
 */
#[AsDoctrineListener(event: Events::onFlush)]
class TripAutoAssignListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $candidates = [];
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof NoteDeFrais || $entity instanceof Commande) {
                $candidates[] = [$entity, true];
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof NoteDeFrais || $entity instanceof Commande) {
                $candidates[] = [$entity, false];
            }
        }
        if ($candidates === []) {
            return;
        }
        $conn = $em->getConnection();
        if ($conn->fetchOne("SELECT to_regclass('gestion_trip')") === null) {
            return; // base pas encore migrée
        }
        foreach ($candidates as [$entity, $inserted]) {
            $changes = $uow->getEntityChangeSet($entity);
            if (!$inserted && !isset($changes['date'])) {
                continue;
            }
            // à la création, Doctrine liste tous les champs : un voyage posé par le code se voit à sa source ; à la modification, au changeset
            $setByCode = $inserted ? $entity->getTripSource() !== null : (array_key_exists('tripId', $changes) || array_key_exists('tripSource', $changes));
            if ($setByCode || $entity->getTripSource() === 'manual' || $entity->getDate() === null) {
                continue;
            }
            if ($entity instanceof Commande && $entity->getKind() === Commande::KIND_REPRISE) {
                continue;
            }
            $tripId = $conn->fetchOne('SELECT id FROM gestion_trip WHERE start_date <= :d AND end_date >= :d ORDER BY id LIMIT 1', ['d' => $entity->getDate()->format('Y-m-d')]);
            $tripId = $tripId === false ? null : (int) $tripId;
            if ($tripId === $entity->getTripId()) {
                continue;
            }
            $entity->setTrip($tripId, $tripId === null ? null : 'auto');
            $uow->recomputeSingleEntityChangeSet($em->getClassMetadata($entity::class), $entity);
        }
    }
}
