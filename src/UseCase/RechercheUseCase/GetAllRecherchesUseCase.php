<?php
namespace App\UseCase\RechercheUseCase;

use App\Dto\RechercheOutputDto;
use App\Services\RechercheService\RechercheService;

class GetAllRecherchesUseCase
{
    private RechercheService $rechercheService;
    public function __construct(RechercheService $rechercheService) { $this->rechercheService = $rechercheService; }

    /**
     * @return RechercheOutputDto[]
     */
    public function execute(string $baseImageUrl, string $locale): array 
    { 
        $recherches = $this->rechercheService->getAllRecherches(); 
        
        return array_map(
            fn($recherche) => new RechercheOutputDto($recherche, $baseImageUrl, $locale),
            $recherches
        );
    }
}