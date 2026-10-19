<?php

namespace App\Dto;

use App\Entity\CashRegisterReport;
use App\Services\CaisseService\CashSessionReportBuilder;

/** Rapport Z tel que rendu par l'API : résumé (liste) ou détail (instantané figé complet) ; montants en cents */
class CashRegisterReportOutputDto
{
    public static function summary(CashRegisterReport $z): array
    {
        $data = $z->getData();

        return [
            'id' => $z->getId(),
            'number' => $z->getNumber(),
            'cashRegister' => ['id' => $z->getCashRegister()->getId(), 'name' => $z->getCashRegister()->getName()],
            'sessionId' => $z->getSession()->getId(),
            'openedAt' => $data['openedAt'] ?? null,
            'closedAt' => $z->getClosedAt()->format(\DateTimeInterface::ATOM),
            'closedBy' => $data['closedBy'] ?? CashSessionReportBuilder::user($z->getClosedBy()),
            'salesTotal' => $data['sales']['total'] ?? 0,
            'expectedCash' => $z->getExpectedCash(),
            'countedCash' => $z->getCountedCash(),
            'discrepancy' => $z->getDiscrepancy(),
        ];
    }

    public static function detail(CashRegisterReport $z): array
    {
        return ['id' => $z->getId(), 'number' => $z->getNumber()] + $z->getData() + [
            'closedAt' => $z->getClosedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
