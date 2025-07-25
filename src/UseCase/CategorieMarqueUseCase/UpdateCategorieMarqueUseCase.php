<?php
namespace App\UseCase\CategorieMarqueUseCase;

use App\Dto\CategorieMarqueInputDto;
use App\Entity\CategorieMarque;
use App\Services\CategorieMarqueService\CategorieMarqueService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateCategorieMarqueUseCase
{
    private CategorieMarqueService $categorieMarqueService;
    public function __construct(CategorieMarqueService $service) { $this->categorieMarqueService = $service; }
    public function execute(int $id, CategorieMarqueInputDto $dto): CategorieMarque
    {
        $categorie = $this->categorieMarqueService->findCategorieMarque($id);
        if (!$categorie) {
            throw new NotFoundHttpException('Catégorie de marque non trouvée.');
        }
        return $this->categorieMarqueService->updateCategorieMarque($categorie, $dto);
    }
}
