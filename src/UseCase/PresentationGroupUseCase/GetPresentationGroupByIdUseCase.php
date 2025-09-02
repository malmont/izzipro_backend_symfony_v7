<?php
namespace App\UseCase\PresentationGroupUseCase;

use App\Entity\PresentationGroup;
use App\Services\PresentationGroupService\PresentationGroupService;

class GetPresentationGroupByIdUseCase
{
    public function __construct(private PresentationGroupService $service) {}

    public function execute(int $id): ?PresentationGroup
    {
        return $this->service->findById($id);
    }
}
