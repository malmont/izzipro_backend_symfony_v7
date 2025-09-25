<?php
namespace App\UseCase\BanniereUseCase;

use App\Dto\BanniereOutputDto;
use App\Services\BanniereService\BanniereService;

class GetAllBannieresUseCase
{
    private BanniereService $banniereService;

    public function __construct(BanniereService $banniereService)
    {
        $this->banniereService = $banniereService;
    }

    /**
     * @return BanniereOutputDto[]
     */
    public function execute(string $locale, string $baseImageUrl): array
    {
        $entities = $this->banniereService->findAllByLocale($locale);
        return array_map(
            fn($banniere) => new BanniereOutputDto($banniere, $baseImageUrl, $locale),
            $entities
        );
    }
}