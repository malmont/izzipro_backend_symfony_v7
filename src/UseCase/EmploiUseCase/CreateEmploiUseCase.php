<?php
namespace App\UseCase\EmploiUseCase;

use App\Dto\EmploiInputDto;
use App\Entity\Emploi;
use App\Services\EmploiService\EmploiService;

class CreateEmploiUseCase
{
    private EmploiService $emploiService;
    public function __construct(EmploiService $emploiService) { $this->emploiService = $emploiService; }
    public function execute(EmploiInputDto $dto): Emploi { return $this->emploiService->createEmploi($dto); }
}
