<?php
namespace App\UseCase\RechercheUseCase;

use App\Dto\RechercheOutputDto;
use App\Entity\Recherche;
use App\Services\RechercheService\RechercheService; 

class GetRechercheByIdUseCase
{
    public function __construct(
        private RechercheService $rechercheService 
    ) {}

    public function execute(int $id, string $baseImageUrl, string $locale): ?RechercheOutputDto
    {
        $recherche = $this->rechercheService->findRecherche($id);

        if (!$recherche) {
            return null;
        }

        return new RechercheOutputDto($recherche, $baseImageUrl, $locale);
    }
}