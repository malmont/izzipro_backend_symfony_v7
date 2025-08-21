<?php
namespace App\UseCase\PresentationUseCase;

use App\Entity\Presentation;
use App\Services\PresentationService\PresentationService;

class GetPresentationByIdUseCase
{
    public function __construct(private PresentationService $service) {}

    public function execute(int $id): ?Presentation
    {
        return $this->service->findById($id);
    }
}
