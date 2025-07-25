<?php
namespace App\UseCase\MultilienUseCase;

use App\Dto\MultilienInputDto;
use App\Entity\Multilien;
use App\Services\MultilienService\MultilienService;

class CreateMultilienUseCase
{
    private MultilienService $multilienService;
    public function __construct(MultilienService $multilienService) { $this->multilienService = $multilienService; }
    public function execute(MultilienInputDto $dto): Multilien { return $this->multilienService->createMultilien($dto); }
}
