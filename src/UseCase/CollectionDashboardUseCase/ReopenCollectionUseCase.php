<?php

namespace App\UseCase\CollectionDashboardUseCase;

use App\Dto\DashboardCollectionDTO;
use App\Entity\Collections;
use App\Services\GestionService\GestionConflictException;
use App\Services\GestionService\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;

/**
 * POST /api/dashboard/collection/{id}/reopen (lot P1, 10/11/2026) : réouverture manuelle d'une période ou d'une collection fermée.
 * Les statistiques figées sont gardées en historique ; la période reste ouverte jusqu'à une clôture manuelle (`close`), la
 * clôture par la date ne la referme pas. 409 `NOT_CLOSED` si déjà ouverte. Rend le tableau de bord vivant.
 */
class ReopenCollectionUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodCalendar $calendar,
        private readonly DashboardCollectionUseCase $dashboard,
    ) {
    }

    public function execute(Collections $collection): DashboardCollectionDTO
    {
        if (!$this->calendar->isClosed($collection)) {
            throw new GestionConflictException('NOT_CLOSED', 'La collection est déjà ouverte.');
        }
        $em = $this->emProvider->getEntityManager();
        $collection->setIsClosed(false)->setClosedAt(null)->setReopenedAt(new \DateTime());
        foreach ($collection->getCommandes() as $commande) {
            $commande->setIsClosed(false);
            $em->persist($commande);
        }
        $em->persist($collection);
        $em->flush();

        return $this->dashboard->execute($collection);
    }
}
