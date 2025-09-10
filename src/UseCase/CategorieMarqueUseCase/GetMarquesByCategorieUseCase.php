<?php
namespace App\UseCase\CategorieMarqueUseCase;

use App\Dto\MarqueOutputDto;
use App\Services\CategorieMarqueService\CategorieMarqueService;

class GetMarquesByCategorieUseCase
{
    public function __construct(
        private CategorieMarqueService $categorieMarqueService 
    ) {
    }

    /**
     * @return MarqueOutputDto[]|null
     */
    public function execute(int $id, string $locale, string $baseImageUrl): ?array 
    {
        $categorie = $this->categorieMarqueService->findCategorieMarque($id);

        if (!$categorie) {
            return null;
        }

        $marques = $categorie->getMarques()->toArray();
        return array_map(
            fn($marque) => new MarqueOutputDto($marque, $baseImageUrl),
            $marques
        );
    }
}