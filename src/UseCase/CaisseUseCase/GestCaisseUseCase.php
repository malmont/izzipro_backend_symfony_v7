<?php
namespace App\UseCase\CaisseUseCase;

use App\Dto\CaisseDTO;
use App\Dto\TransationCaisseDTO;
use App\Services\CaisseService\CaisseService;

class GestCaisseUseCase
{
    private $caisseService;

    public function __construct(CaisseService $caisseService)
    {
        $this->caisseService =  $caisseService;
    }

    public function execute(?int $days = null): array
    {
        $caisses = $this->caisseService->getCaisse($days);
        $caisseDTOS = [];

        foreach ($caisses as $caisse) {
            $transactionCaisseDTO = [];
            
            foreach ($caisse->getTransactionCaisses() as $transactionCaisse) {
                $transactionCaisseDTO[] = new TransationCaisseDTO($transactionCaisse);
            }

            $caisseDTOS[] = new CaisseDTO(
                $caisse->getId(),
                $caisse->getAmountTotal(),
                $caisse->getFonDeCaisse(),
                $caisse->getCreatedAt()->format('Y-m-d H:i:s'),
                $caisse->isOpen(),
                $transactionCaisseDTO
            );
        }

        return $caisseDTOS;
    }
}
