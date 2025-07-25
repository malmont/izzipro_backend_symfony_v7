<?php
namespace App\UseCase\MarqueUseCase;

use App\Dto\MarqueInputDto;
use App\Entity\Marque;
use App\Services\MarqueService\MarqueService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateMarqueUseCase
{
    private MarqueService $marqueService;
    public function __construct(MarqueService $marqueService) { $this->marqueService = $marqueService; }
    public function execute(int $id, MarqueInputDto $dto): Marque
    {
        $marque = $this->marqueService->findMarque($id);
        if (!$marque) { throw new NotFoundHttpException('Marque non trouvée.'); }
        return $this->marqueService->updateMarque($marque, $dto);
    }
}
