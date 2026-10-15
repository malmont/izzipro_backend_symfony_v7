<?php
namespace App\UseCase\CaisseUseCase;

use App\Dto\CaisseDTO;
use App\Dto\TransationCaisseDTO;
use App\Entity\CashRegister;
use App\Services\CaisseService\CaisseService;

/** Liste des sessions de caisse (GET /api/caisse), d'un poste ou de tous */
class GestCaisseUseCase
{
    public function __construct(private readonly CaisseService $caisseService)
    {
    }

    /** @return list<CaisseDTO> */
    public function execute(?int $days = null, ?CashRegister $register = null): array
    {
        $caisseDTOS = [];
        foreach ($this->caisseService->getCaisse($days, $register) as $caisse) {
            $transactionCaisseDTO = [];
            foreach ($caisse->getTransactionCaisses() as $transactionCaisse) {
                $transactionCaisseDTO[] = new TransationCaisseDTO($transactionCaisse);
            }
            $caisseDTOS[] = CaisseDTO::fromEntity($caisse, $transactionCaisseDTO);
        }

        return $caisseDTOS;
    }
}
