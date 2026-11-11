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

    /** @return array{items: list<\App\Entity\Fournisseur>, total: int} liste filtrée et paginable (lot G5, 10/11/2026) */
    public function search(?\App\Gestion\Services\GestionListQuery $query): array
    {
        return $this->fournisseurService->searchFournisseurs($query);
    }
}
