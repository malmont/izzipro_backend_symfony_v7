<?php
namespace App\UseCase\BaniereStatiqueUseCase;

use App\Dto\BaniereStatiqueOutputDto;
use App\Services\BaniereStatiqueService\BaniereStatiqueService;

class GetAllBaniereStatiquesUseCase
{
    public function __construct(private BaniereStatiqueService $service) {}

    /**
     * @return BaniereStatiqueOutputDto[]
     */
    public function execute(string $locale, string $baseImageUrl): array
    {
        $entities = $this->service->findAllByLocale($locale);
        return array_map(
            fn($entity) => new BaniereStatiqueOutputDto($entity, $baseImageUrl, $locale),
            $entities
        );
    }
}