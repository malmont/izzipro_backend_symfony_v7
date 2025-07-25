<?php
namespace App\UseCase\MarqueUseCase;

use App\Services\MarqueService\MarqueService;

class GetAllMarquesUseCase
{
    private MarqueService $marqueService;
    public function __construct(MarqueService $marqueService) { $this->marqueService = $marqueService; }
    public function execute(): array { return $this->marqueService->getAllMarques(); }
}
