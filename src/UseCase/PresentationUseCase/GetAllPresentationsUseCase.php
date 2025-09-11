<?php
namespace App\UseCase\PresentationUseCase;

use App\Dto\PresentationOutputDto;
use App\Services\PresentationService\PresentationService;

class GetAllPresentationsUseCase
{
    public function __construct(private PresentationService $service) {}

    /**
     * @return PresentationOutputDto[]
     */
    public function execute(string $baseImageUrl, string $locale): array
    {
        $entities = $this->service->findAll();
        return array_map(
            fn($entity) => new PresentationOutputDto($entity, $baseImageUrl, $locale),
            $entities
        );
    }
}