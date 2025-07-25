<?php
namespace App\UseCase\CategorieMarqueUseCase;

use App\Dto\CategorieMarqueInputDto;
use App\Entity\CategorieMarque;
use App\Services\CategorieMarqueService\CategorieMarqueService;

class CreateCategorieMarqueUseCase
{
    private CategorieMarqueService $categorieMarqueService;
    public function __construct(CategorieMarqueService $service) { $this->categorieMarqueService = $service; }
    public function execute(CategorieMarqueInputDto $dto): CategorieMarque { return $this->categorieMarqueService->createCategorieMarque($dto); }
}