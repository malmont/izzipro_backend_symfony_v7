<?php
namespace App\UseCase\FournisseurUseCase;

use App\Entity\Fournisseur;
use App\Services\FournisseurService\FournisseurService;

class DeleteFournisseurUseCase
{
    private FournisseurService $fournisseurService;

    public function __construct(FournisseurService $fournisseurService)
    {
        $this->fournisseurService = $fournisseurService;
    }

    public function execute(Fournisseur $fournisseur): void
    {
        $this->fournisseurService->deleteFournisseur($fournisseur);
    }
}
