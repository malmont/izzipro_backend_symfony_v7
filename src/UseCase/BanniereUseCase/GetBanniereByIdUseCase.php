<?php
namespace App\UseCase\BanniereUseCase;

use App\Dto\BanniereOutputDto;
use App\Entity\Banniere;
use App\Services\BanniereService\BanniereService;

class GetBanniereByIdUseCase
{
    public function __construct(
        private BanniereService $banniereService 
    ) {
    }

    public function execute(int $id, string $locale, string $baseImageUrl): ?BanniereOutputDto
    {
        $entity = $this->banniereService->findByIdAndLocale($id, $locale);

        if (!$entity) {
            return null;
        }
        return new BanniereOutputDto($entity, $baseImageUrl, $locale);
    }
}