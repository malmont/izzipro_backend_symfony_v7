<?php
namespace App\UseCase\FournisseurUseCase;

use App\Dto\FournisseurInputDTO;
use App\Entity\Fournisseur;
use App\Services\FournisseurService\FournisseurService;

class CreateFournisseurUseCase
{
    private FournisseurService $fournisseurService;

    public function __construct(FournisseurService $fournisseurService)
    {
        $this->fournisseurService = $fournisseurService;
    }

    public function execute(FournisseurInputDTO $inputDTO): Fournisseur
    {
        return $this->fournisseurService->createFournisseur($inputDTO);
    }
}
