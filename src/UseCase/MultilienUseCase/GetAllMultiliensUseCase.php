<?php
namespace App\UseCase\MultilienUseCase;

use App\Services\MultilienService\MultilienService;

class GetAllMultiliensUseCase
{
    private MultilienService $multilienService;
    public function __construct(MultilienService $multilienService) { $this->multilienService = $multilienService; }
    public function execute(): array { return $this->multilienService->getAllMultiliens(); }
}
