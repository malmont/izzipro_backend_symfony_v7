<?php
namespace App\UseCase\FraisDePortUseCase;

use App\Entity\Commande;
use App\Services\FraisDePortService\FraisDePortService;

class DeleteFraisDePortUseCase
{
    private FraisDePortService $fraisDePortService;

    public function __construct(FraisDePortService $fraisDePortService)
    {
        $this->fraisDePortService = $fraisDePortService;
    }

    public function execute(Commande $commande): void
    {
        $this->fraisDePortService->deleteFraisDePort($commande);
    }
}
