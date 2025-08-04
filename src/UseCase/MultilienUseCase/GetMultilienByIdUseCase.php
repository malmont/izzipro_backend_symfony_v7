<?php

namespace App\UseCase\MultilienUseCase;

use App\Entity\Multilien;
use App\Services\MultilienService\MultilienService;

class GetMultilienByIdUseCase
{
    public function __construct(
        private MultilienService $multilienService 
    ) {
    }
    public function execute(int $id): ?Multilien
    {
        return $this->multilienService->findMultilien($id);
    }
}
