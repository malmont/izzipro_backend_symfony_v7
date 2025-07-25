<?php
namespace App\UseCase\EmploiUseCase;

use App\Dto\EmploiInputDto;
use App\Entity\Emploi;
use App\Services\EmploiService\EmploiService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateEmploiUseCase
{
    private EmploiService $emploiService;
    public function __construct(EmploiService $emploiService) { $this->emploiService = $emploiService; }
    public function execute(int $id, EmploiInputDto $dto): Emploi
    {
        $emploi = $this->emploiService->findEmploi($id);
        if (!$emploi) { throw new NotFoundHttpException('Offre d\'emploi non trouvée.'); }
        return $this->emploiService->updateEmploi($emploi, $dto);
    }
}