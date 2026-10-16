<?php

namespace App\MemoiresVivantes\UseCase\Admin;

use App\MemoiresVivantes\Services\PaymentLedgerService;

/** GET /api/memoires/admin/payments : registre des paiements de l'application mobile (JSON ou CSV) */
class ListPaymentsUseCase
{
    public function __construct(private readonly PaymentLedgerService $ledger)
    {
    }

    /** @throws \InvalidArgumentException paramètre invalide */
    public function execute(array $query): array
    {
        return $this->ledger->list($query);
    }

    /** @throws \InvalidArgumentException paramètre invalide */
    public function csv(array $query): string
    {
        return $this->ledger->csv($query);
    }
}
