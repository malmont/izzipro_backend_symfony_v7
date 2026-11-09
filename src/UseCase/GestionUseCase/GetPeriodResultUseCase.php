<?php

namespace App\UseCase\GestionUseCase;

use App\Entity\Collections;
use App\Entity\PeriodResult;
use App\Services\GestionService\GestionConflictException;
use App\Services\GestionService\PeriodCalendar;
use App\Services\GestionService\PeriodResultCalculator;
use App\Services\TenantEntityManagerProvider;

/**
 * GET /api/periodes/{id}/resultat (lot P2, 10/11/2026) : résultat vivant d'une période ouverte ; pour une période fermée, résultat
 * figé à la première lecture (`period_result`) avec une coupure des coûts : les rattachements postérieurs à la fermeture (clôture
 * manuelle, sinon échéance = fin + 15 jours) n'entrent pas dans le calcul, quelle que soit la date de la première lecture. Une
 * période rouverte se calcule sans coupure (les rattachements faits pendant la réouverture comptent) et sa nouvelle clôture
 * devient la coupure.
 * 404 `NOT_A_PERIOD` pour une collection manuelle.
 */
class GetPeriodResultUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodCalendar $calendar,
        private readonly PeriodResultCalculator $calculator,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(Collections $collection): array
    {
        if (!$collection->isPeriod()) {
            throw new GestionConflictException('NOT_A_PERIOD', 'Collection manuelle : pas de résultat de période.', 404);
        }
        $em = $this->emProvider->getEntityManager();
        $closed = $this->calendar->isClosed($collection);
        if ($closed) {
            $frozen = $em->getRepository(PeriodResult::class)->findLatestByCollection($collection);
            if ($frozen !== null) {
                return $frozen->getPayload();
            }
        }
        $payload = $this->calculator->compute($collection, $closed ? $this->costCutoff($collection) : null);
        $payload['frozen'] = $closed;
        $payload['frozenAt'] = null;
        if ($closed) {
            $now = new \DateTimeImmutable();
            $payload['frozenAt'] = $now->format(\DateTimeInterface::ATOM);
            $em->persist((new PeriodResult())->setCollection($collection)->setComputedAt($now)->setPayload($payload));
            $em->flush();
        }

        return $payload;
    }

    /** Coupure des coûts d'une période fermée : sa clôture manuelle, sinon son échéance */
    public function costCutoff(Collections $collection): \DateTimeImmutable
    {
        return $this->calendar->closedAt($collection) ?? $this->calendar->deadline($collection);
    }
}
