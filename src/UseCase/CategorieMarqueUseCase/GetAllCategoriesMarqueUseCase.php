<?php
namespace App\UseCase\CategorieMarqueUseCase;

use App\Dto\CategorieMarqueOutputDto;
use App\Services\CategorieMarqueService\CategorieMarqueService;

class GetAllCategoriesMarqueUseCase
{
    private CategorieMarqueService $categorieMarqueService;
    public function __construct(CategorieMarqueService $service) { $this->categorieMarqueService = $service; }

    /**
     * @return CategorieMarqueOutputDto[]
     */
    public function execute(string $locale, string $baseImageUrl): array 
    { 
        $categories = $this->categorieMarqueService->getAllCategoriesMarque($locale); 
                return array_map(
            fn($cat) => new CategorieMarqueOutputDto($cat, $baseImageUrl, $locale), 
            $categories
        );
    }
}