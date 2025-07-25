<?php
namespace App\UseCase\CategorieMarqueUseCase;

use App\Services\CategorieMarqueService\CategorieMarqueService;

class GetAllCategoriesMarqueUseCase
{
    private CategorieMarqueService $categorieMarqueService;
    public function __construct(CategorieMarqueService $service) { $this->categorieMarqueService = $service; }
    public function execute(): array { return $this->categorieMarqueService->getAllCategoriesMarque(); }
}
