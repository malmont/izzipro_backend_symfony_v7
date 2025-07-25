<?php
namespace App\UseCase\MultilienUseCase;

use App\Services\MultilienService\MultilienService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DeleteMultilienUseCase
{
    private MultilienService $multilienService;
    public function __construct(MultilienService $multilienService) { $this->multilienService = $multilienService; }
    public function execute(int $id): void
    {
        $multilien = $this->multilienService->findMultilien($id);
        if (!$multilien) { throw new NotFoundHttpException('Multilien non trouvé.'); }
        $this->multilienService->deleteMultilien($multilien);
    }
}