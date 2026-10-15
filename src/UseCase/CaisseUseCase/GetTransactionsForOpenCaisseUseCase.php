<?php
namespace App\UseCase\CaisseUseCase;

use App\Entity\CashRegister;
use App\Entity\TransactionCaisse;
use App\Services\CaisseService\CaisseService;

/** Transactions de la session ouverte d'un poste (GET /api/caisse/transactions) */
class GetTransactionsForOpenCaisseUseCase
{
    public function __construct(private readonly CaisseService $caisseService)
    {
    }

    /** @return list<TransactionCaisse> */
    public function execute(CashRegister $register): array
    {
        return $this->caisseService->getTransactionsForOpenCaisse($register);
    }
}
