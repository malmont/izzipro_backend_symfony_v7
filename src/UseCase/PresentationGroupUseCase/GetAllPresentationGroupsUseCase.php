<?php
namespace App\UseCase\PresentationGroupUseCase;

use App\Dto\PresentationGroupOutputDto;
use App\Services\PresentationGroupService\PresentationGroupService;

class GetAllPresentationGroupsUseCase
{
    public function __construct(private PresentationGroupService $service) {}

    /**
     * @return PresentationGroupOutputDto[]
     */
    public function execute(string $baseImageUrl, string $locale): array
    {
        $entities = $this->service->findAllByLocale($locale);
        
        return array_map(
            fn($entity) => new PresentationGroupOutputDto($entity, $baseImageUrl, $locale),
            $entities
        );
    }
}