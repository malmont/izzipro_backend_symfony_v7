<?php
namespace App\UseCase\PresentationUseCase;

use App\Services\PresentationService\PresentationService;

class GetAllPresentationsUseCase
{
    public function __construct(private PresentationService $service) {}

    public function execute(): array
    {
        return $this->service->findAll();
    }
}