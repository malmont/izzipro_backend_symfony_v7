<?php
namespace App\UseCase\RechercheUseCase;

use App\Services\RechercheService\RechercheService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DeleteRechercheUseCase
{
    private RechercheService $rechercheService;
    public function __construct(RechercheService $rechercheService) { $this->rechercheService = $rechercheService; }
    public function execute(int $id): void
    {
        $recherche = $this->rechercheService->findRecherche($id);
        if (!$recherche) { throw new NotFoundHttpException('Entité Recherche non trouvée.'); }
        $this->rechercheService->deleteRecherche($recherche);
    }
}