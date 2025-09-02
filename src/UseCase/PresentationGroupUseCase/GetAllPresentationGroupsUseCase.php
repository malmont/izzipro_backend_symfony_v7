<?php
namespace App\UseCase\PresentationGroupUseCase;

use App\Services\PresentationGroupService\PresentationGroupService;

class GetAllPresentationGroupsUseCase
{
    public function __construct(private PresentationGroupService $service) {}

    public function execute(): array
    {
        return $this->service->findAll();
    }
}