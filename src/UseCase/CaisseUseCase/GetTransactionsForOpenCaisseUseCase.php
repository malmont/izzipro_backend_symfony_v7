<?php
namespace App\UseCase\CaisseUseCase;

use App\Services\CaisseService\CaisseService;

class GetTransactionsForOpenCaisseUseCase
{
    private $caisseService;

    public function __construct(CaisseService $caisseService)
    {
        $this->caisseService = $caisseService;
    }

    public function execute(): array
    {
        return $this->caisseService->getTransactionsForOpenCaisse();
    }
}
