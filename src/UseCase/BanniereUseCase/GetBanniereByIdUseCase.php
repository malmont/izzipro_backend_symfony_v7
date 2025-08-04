<?php

namespace App\UseCase\BanniereUseCase;

use App\Entity\Banniere;
use App\Services\BanniereService\BanniereService;

class GetBanniereByIdUseCase
{
    public function __construct(
        private BanniereService $banniereService 
    ) {
    }

    public function execute(int $id): ?Banniere
    {
        return $this->banniereService->findBanniere($id);
    }
}