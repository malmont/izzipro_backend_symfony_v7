<?php
namespace App\UseCase\PresentationUseCase;

use App\Dto\PresentationOutputDto;
use App\Services\PresentationService\PresentationService;

class GetPresentationByIdUseCase
{
    public function __construct(private PresentationService $service) {}

    public function execute(int $id, string $baseImageUrl, string $locale): ?PresentationOutputDto
    {
        $entity = $this->service->findByIdAndLocale($id, $locale);

        if (!$entity) {
            return null;
        }
        return new PresentationOutputDto($entity, $baseImageUrl, $locale);
    }
}