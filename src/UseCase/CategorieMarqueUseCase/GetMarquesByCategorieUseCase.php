<?php

namespace App\UseCase\CategorieMarqueUseCase;

use App\Entity\CategorieMarque;
use App\Services\CategorieMarqueService\CategorieMarqueService;

class GetMarquesByCategorieUseCase
{
    public function __construct(
        private CategorieMarqueService $categorieMarqueService 
    ) {
    }

    public function execute(int $id): ?array 
    {
        $categorie = $this->categorieMarqueService->findCategorieMarque($id);

        if (!$categorie) {
            return null;
        }
        return $categorie->getMarques()->toArray();
    }
}