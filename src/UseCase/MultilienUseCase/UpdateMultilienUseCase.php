<?php
namespace App\UseCase\MultilienUseCase;

use App\Dto\MultilienInputDto;
use App\Entity\Multilien;
use App\Services\MultilienService\MultilienService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateMultilienUseCase
{
    private MultilienService $multilienService;
    public function __construct(MultilienService $multilienService) { $this->multilienService = $multilienService; }
    public function execute(int $id, MultilienInputDto $dto): Multilien
    {
        $multilien = $this->multilienService->findMultilien($id);
        if (!$multilien) { throw new NotFoundHttpException('Multilien non trouvé.'); }
        return $this->multilienService->updateMultilien($multilien, $dto);
    }
}