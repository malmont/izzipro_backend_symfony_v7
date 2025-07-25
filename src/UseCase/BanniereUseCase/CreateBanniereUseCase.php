<?php
namespace App\UseCase\BanniereUseCase;

use App\Dto\BanniereInputDto;
use App\Entity\Banniere;
use App\Services\BanniereService\BanniereService;

class CreateBanniereUseCase
{
    private BanniereService $banniereService;

    public function __construct(BanniereService $banniereService)
    {
        $this->banniereService = $banniereService;
    }

    public function execute(BanniereInputDto $dto): Banniere
    {
        return $this->banniereService->createBanniere($dto);
    }
}
