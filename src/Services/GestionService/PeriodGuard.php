<?php

namespace App\Services\GestionService;

use App\Entity\Collections;
use App\Entity\Commande;

/**
 * Garde d'écriture des périodes (lot P1, 10/11/2026, `docs/periodes-gestion.md`) : toute écriture datée dans une période fermée
 * est refusée, 409 `PERIOD_CLOSED { periodId, periodKey }` ; une collection manuelle fermée : 409 `COLLECTION_CLOSED` ; une
 * commande figée : 409 `COMMANDE_CLOSED`. Une seule règle pour les commandes, frais de port, produits, rattachements, notes de frais.
 */
class PeriodGuard
{
    public function __construct(private readonly PeriodCalendar $calendar)
    {
    }

    /** La période contenant la date doit être ouverte (créée si manquante) ; rend la période, ou null si la date est hors calendrier */
    public function assertDateWritable(\DateTimeInterface $date): ?Collections
    {
        $period = $this->calendar->periodFor($date);
        if ($period !== null && $this->calendar->isClosed($period)) {
            throw $this->closed($period);
        }

        return $period;
    }

    /** Collection cible d'une écriture : période ou manuelle, ouverte */
    public function assertCollectionWritable(Collections $collection): void
    {
        if (!$this->calendar->isClosed($collection)) {
            return;
        }
        throw $collection->isPeriod() ? $this->closed($collection) : new GestionConflictException('COLLECTION_CLOSED', 'La collection est figée.');
    }

    /** Commande cible d'une écriture : ni figée, ni datée dans une période fermée */
    public function assertCommandeWritable(Commande $commande): void
    {
        if ($commande->getIsClosed()) {
            throw new GestionConflictException('COMMANDE_CLOSED', 'La commande est figée.');
        }
        if ($commande->getDate() !== null) {
            $this->assertDateWritable($commande->getDate());
        }
    }

    public function closed(Collections $period): GestionConflictException
    {
        return new GestionConflictException('PERIOD_CLOSED', sprintf('La période « %s » est clôturée : aucune écriture datée dedans.', $period->getNomCollection()), 409, ['periodId' => $period->getId(), 'periodKey' => $period->getPeriodKey()]);
    }
}
