<?php
namespace App\UseCase\BanniereUseCase;

use App\Services\BanniereService\BanniereService;

class GetAllBannieresUseCase
{
    private BanniereService $banniereService;

    public function __construct(BanniereService $banniereService)
    {
        $this->banniereService = $banniereService;
    }

    public function execute(): array
    {
        return $this->banniereService->getAllBannieres();
    }
}