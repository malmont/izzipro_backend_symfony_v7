<?php
namespace App\UseCase\PresentationUseCase;

use App\Dto\PresentationOutputDto;
use App\Entity\Presentation;
use App\Services\PresentationService\PresentationService;

class GetPresentationByIdUseCase
{
    public function __construct(private PresentationService $service) {}

    public function execute(int $id, string $baseImageUrl, string $locale): ?PresentationOutputDto
    {
        $entity = $this->service->findById($id);

        if (!$entity) {
            return null;
        }
        return new PresentationOutputDto($entity, $baseImageUrl, $locale);
    }
}