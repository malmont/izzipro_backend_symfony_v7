<?php

namespace App\UseCase\RechercheUseCase;

use App\Entity\Recherche;
use App\Services\RechercheService\RechercheService; 

class GetRechercheByIdUseCase
{
    public function __construct(
        private RechercheService $rechercheService 
    ) {
    }

    public function execute(int $id): ?Recherche
    {
        return $this->rechercheService->findRecherche($id);
    }
}
