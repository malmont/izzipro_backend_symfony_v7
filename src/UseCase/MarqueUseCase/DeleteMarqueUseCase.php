<?php
namespace App\UseCase\MarqueUseCase;

use App\Services\MarqueService\MarqueService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DeleteMarqueUseCase
{
    private MarqueService $marqueService;
    public function __construct(MarqueService $marqueService) { $this->marqueService = $marqueService; }
    public function execute(int $id): void
    {
        $marque = $this->marqueService->findMarque($id);
        if (!$marque) { throw new NotFoundHttpException('Marque non trouvée.'); }
        $this->marqueService->deleteMarque($marque);
    }
}