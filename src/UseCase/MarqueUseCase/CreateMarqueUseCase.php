<?php
namespace App\UseCase\MarqueUseCase;

use App\Dto\MarqueInputDto;
use App\Entity\Marque;
use App\Services\MarqueService\MarqueService;

class CreateMarqueUseCase
{
    private MarqueService $marqueService;
    public function __construct(MarqueService $marqueService) { $this->marqueService = $marqueService; }
    public function execute(MarqueInputDto $dto): Marque { return $this->marqueService->createMarque($dto); }
}