<?php
namespace App\UseCase\RechercheUseCase;

use App\Services\RechercheService\RechercheService;

class GetAllRecherchesUseCase
{
    private RechercheService $rechercheService;
    public function __construct(RechercheService $rechercheService) { $this->rechercheService = $rechercheService; }
    public function execute(): array { return $this->rechercheService->getAllRecherches(); }
}
