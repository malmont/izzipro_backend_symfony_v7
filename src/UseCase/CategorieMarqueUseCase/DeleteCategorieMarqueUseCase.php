<?php
namespace App\UseCase\CategorieMarqueUseCase;

use App\Services\CategorieMarqueService\CategorieMarqueService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DeleteCategorieMarqueUseCase
{
    private CategorieMarqueService $categorieMarqueService;
    public function __construct(CategorieMarqueService $service) { $this->categorieMarqueService = $service; }
    public function execute(int $id): void
    {
        $categorie = $this->categorieMarqueService->findCategorieMarque($id);
        if (!$categorie) {
            throw new NotFoundHttpException('Catégorie de marque non trouvée.');
        }
        $this->categorieMarqueService->deleteCategorieMarque($categorie);
    }
}
