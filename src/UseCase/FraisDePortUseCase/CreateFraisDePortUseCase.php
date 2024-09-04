<?php
namespace App\UseCase\FraisDePortUseCase;

use App\Entity\Commande;
use App\Dto\FraisDePortInputDTO;
use App\Services\FraisDePortService\FraisDePortService;

class CreateFraisDePortUseCase
{
    private FraisDePortService $fraisDePortService;

    public function __construct(FraisDePortService $fraisDePortService)
    {
        $this->fraisDePortService = $fraisDePortService;
    }

    public function execute(Commande $commande, FraisDePortInputDTO $inputDTO): void
    {
        $this->fraisDePortService->createFraisDePort($commande, $inputDTO);
    }
}
