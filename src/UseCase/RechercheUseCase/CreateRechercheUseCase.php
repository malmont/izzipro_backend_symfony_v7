<?php
namespace App\UseCase\RechercheUseCase;

use App\Dto\RechercheInputDto;
use App\Entity\Recherche;
use App\Services\RechercheService\RechercheService;

class CreateRechercheUseCase
{
    private RechercheService $rechercheService;
    public function __construct(RechercheService $rechercheService) { $this->rechercheService = $rechercheService; }
    public function execute(RechercheInputDto $dto): Recherche { return $this->rechercheService->createRecherche($dto); }
}
