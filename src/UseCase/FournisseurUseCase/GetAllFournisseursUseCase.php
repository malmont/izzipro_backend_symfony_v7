<?php
namespace App\UseCase\FournisseurUseCase;

use App\Services\FournisseurService\FournisseurService;

class GetAllFournisseursUseCase
{
    private FournisseurService $fournisseurService;

    public function __construct(FournisseurService $fournisseurService)
    {
        $this->fournisseurService = $fournisseurService;
    }

    public function execute(): array
    {
        return $this->fournisseurService->getAllFournisseurs();
    }
}
