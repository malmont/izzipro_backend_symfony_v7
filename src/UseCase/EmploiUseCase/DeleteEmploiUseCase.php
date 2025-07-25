<?php
namespace App\UseCase\EmploiUseCase;

use App\Services\EmploiService\EmploiService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DeleteEmploiUseCase
{
    private EmploiService $emploiService;
    public function __construct(EmploiService $emploiService) { $this->emploiService = $emploiService; }
    public function execute(int $id): void
    {
        $emploi = $this->emploiService->findEmploi($id);
        if (!$emploi) { throw new NotFoundHttpException('Offre d\'emploi non trouvée.'); }
        $this->emploiService->deleteEmploi($emploi);
    }
}