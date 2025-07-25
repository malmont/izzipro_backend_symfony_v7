<?php
namespace App\UseCase\EmploiUseCase;

use App\Services\EmploiService\EmploiService;

class GetAllEmploisUseCase
{
    private EmploiService $emploiService;
    public function __construct(EmploiService $emploiService) { $this->emploiService = $emploiService; }
    public function execute(): array { return $this->emploiService->getAllEmplois(); }
}
