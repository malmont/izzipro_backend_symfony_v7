<?php

namespace App\Services\CaisseService;

use App\Entity\TypeCash;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;

/**
 * Coupures (billets et pièces) de la devise du site, servies au POS (GET /api/caisse/denominations, 19/10/2026), et
 * contrôle des comptages `cashDetails` [{typeCash, nombreItems}] (dépôts, retraits, fermeture).
 */
class DenominationService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly TenantCurrencyProvider $currency)
    {
    }

    /** @return list<array{id: int, label: string, valueCents: int, kind: ?string, active: bool}> */
    public function list(): array
    {
        $rows = $this->emProvider->getEntityManager()->createQueryBuilder()->select('t')->from(TypeCash::class, 't')
            ->andWhere('t.currency = :currency')->setParameter('currency', $this->currency->code())
            ->orderBy('t.position', 'ASC')->addOrderBy('t.id', 'ASC')->getQuery()->getResult();

        return array_map(fn (TypeCash $t) => [
            'id' => (int) $t->getId(), 'label' => (string) $t->getName(), 'valueCents' => $t->getValueCents(), 'kind' => $t->getKind(), 'active' => $t->isActive(),
        ], $rows);
    }

    /**
     * Comptage détaillé et total (cents) ; coupure inconnue ou nombre invalide → 400 INVALID_CASH_DETAILS.
     *
     * @param mixed $cashDetails
     * @return array{lines: list<array{typeCash: int, label: string, valueCents: int, count: int, totalCents: int}>, total: int}
     */
    public function count(mixed $cashDetails): array
    {
        if (!is_array($cashDetails)) {
            throw new CashRegisterException('INVALID_CASH_DETAILS', 'cashDetails : liste [{typeCash, nombreItems}] attendue', 400);
        }
        $em = $this->emProvider->getEntityManager();
        $lines = [];
        $total = 0;
        foreach (array_values($cashDetails) as $i => $detail) {
            $typeId = is_array($detail) ? ($detail['typeCash'] ?? null) : null;
            $count = is_array($detail) ? ($detail['nombreItems'] ?? null) : null;
            if (!is_numeric($typeId) || !is_numeric($count) || (int) $count < 0 || (float) $count != (int) $count) {
                throw new CashRegisterException('INVALID_CASH_DETAILS', sprintf('cashDetails[%d] : typeCash et nombreItems (entier positif) attendus', $i), 400);
            }
            $type = $em->find(TypeCash::class, (int) $typeId);
            if (!$type) {
                throw new CashRegisterException('INVALID_CASH_DETAILS', sprintf('cashDetails[%d] : coupure %d inconnue', $i, (int) $typeId), 400);
            }
            $lineTotal = $type->getValueCents() * (int) $count;
            $total += $lineTotal;
            $lines[] = ['typeCash' => (int) $type->getId(), 'label' => (string) $type->getName(), 'valueCents' => $type->getValueCents(), 'count' => (int) $count, 'totalCents' => $lineTotal];
        }

        return ['lines' => $lines, 'total' => $total];
    }
}
