<?php
namespace App\UseCase\RechercheUseCase;

use App\Dto\RechercheInputDto;
use App\Entity\Recherche;
use App\Services\RechercheService\RechercheService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateRechercheUseCase
{
    private RechercheService $rechercheService;
    public function __construct(RechercheService $rechercheService) { $this->rechercheService = $rechercheService; }
    public function execute(int $id, RechercheInputDto $dto): Recherche
    {
        $recherche = $this->rechercheService->findRecherche($id);
        if (!$recherche) { throw new NotFoundHttpException('Entité Recherche non trouvée.'); }
        return $this->rechercheService->updateRecherche($recherche, $dto);
    }
}
