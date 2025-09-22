<?php
namespace App\UseCase\PresentationGroupUseCase;

use App\Dto\PresentationGroupOutputDto;
use App\Entity\PresentationGroup;
use App\Services\PresentationGroupService\PresentationGroupService;

class GetPresentationGroupByIdUseCase
{
    public function __construct(private PresentationGroupService $service) {}

    public function execute(int $id, string $baseImageUrl, string $locale): ?PresentationGroupOutputDto
    {
        $entity = $this->service->findByIdAndLocale($id, $locale);
        if (!$entity) {
            return null;
        }
        return new PresentationGroupOutputDto($entity, $baseImageUrl, $locale);
    }
}